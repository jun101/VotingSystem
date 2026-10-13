import { expect, test, type Browser, type Locator, type Page } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { PASSWORD, registerAndEnter, verifyEmail } from '../support/admin';
import { createBallot } from '../support/ballots';
import { createCandidate } from '../support/candidates';
import { createElection } from '../support/elections';
import { createParty } from '../support/parties';
import { ADMIN_EMAIL, ADMIN_PASSWORD, findInstitution, signInAs } from '../support/platform';
import { castVoteFor, closeNow } from '../support/results';
import { acceptInNewBrowser } from '../support/team';
import { createVoter, readGroups } from '../support/voters';
import { phaseOf, waitUntilOpen } from '../support/voting';
import { linkIn, uniqueEmail, waitForMails } from '../support/mail';

/*
 * Takes the pictures of the user manuals (`/guide/[role]`) by walking every role through every screen, and writes
 * `public/guide/*.jpg` and `src/components/guide/shots.json` (each picture's size and the centre of its numbered
 * markers). Run on demand, never in the test suites: `make guide-shots` (needs the stack and the platform admin that
 * `make e2e` creates). Desktop project only.
 */

test.describe.configure({ mode: 'serial' });
test.skip(!process.env.GUIDE_SHOTS, 'on demand: GUIDE_SHOTS=1 npx playwright test e2e/guide/capture.spec.ts --project=desktop');

const OUT = resolve(process.cwd(), 'public/guide');
const SHOTS_JSON = resolve(process.cwd(), 'src/components/guide/shots.json');
const missing: string[] = [];

type Target = string | Locator;
type ShotOptions = { full?: boolean; settle?: number };

function locate(page: Page, target: Target): Locator {
  return typeof target === 'string' ? page.getByTestId(target) : target;
}

type Box = { x: number; y: number; w: number; h: number };

async function boxesOf(page: Page, targets: Target[]): Promise<(Box | null)[]> {
  const boxes: (Box | null)[] = [];

  for (const target of targets) {
    const locator = locate(page, target).first();

    boxes.push(
      (await locator.count())
        ? await locator.evaluate((element) => {
            const rect = element.getBoundingClientRect();

            return { x: rect.left + window.scrollX, y: rect.top + window.scrollY, w: rect.width, h: rect.height };
          })
        : null,
    );
  }

  return boxes;
}

/**
 * A picture of the page and the centres of the markers (a card is marked at its top left corner). When a marked part
 * is below the fold, the window is made as tall as needed first (up to 2400 px), so a dialog or a long page is whole.
 */
async function shoot(page: Page, name: string, targets: Target[] = [], options: ShotOptions = {}): Promise<void> {
  mkdirSync(OUT, { recursive: true });
  const base = page.viewportSize() ?? { width: 1280, height: 800 };
  await page.waitForTimeout(options.settle ?? 900);

  let boxes = await boxesOf(page, targets);
  const needed = Math.max(0, ...boxes.map((box) => (box ? box.y + Math.min(box.h, 200) + 40 : 0)));
  const wanted = options.full
    ? await page.evaluate(() => document.documentElement.scrollHeight)
    : Math.max(base.height, Math.min(Math.ceil(needed) + 120, 2400));

  if (wanted > base.height) {
    await page.setViewportSize({ width: base.width, height: Math.min(wanted, 2400) });
    await page.waitForTimeout(500);
    boxes = await boxesOf(page, targets);
  }

  const size = await page.evaluate(() => ({ width: document.documentElement.clientWidth, height: window.innerHeight }));
  const markers: [number, number][] = [];

  boxes.forEach((box, index) => {
    if (!box || box.w === 0) {
      missing.push(`${name}: ${String(targets[index])}`);

      return;
    }

    const big = box.w > 420 || box.h > 160;
    const x = big ? box.x + 30 : box.x + box.w / 2;
    const y = big ? box.y + 30 : box.y + box.h / 2;

    markers.push([Math.round((x / size.width) * 1000) / 10, Math.round((y / size.height) * 1000) / 10]);
  });

  await page.screenshot({ path: resolve(OUT, `${name}.jpg`), type: 'jpeg', quality: 72 });
  if (wanted > base.height) await page.setViewportSize(base);

  const known = existsSync(SHOTS_JSON) ? (JSON.parse(readFileSync(SHOTS_JSON, 'utf8')) as Record<string, unknown>) : {};
  known[name] = { width: size.width, height: size.height, markers };
  writeFileSync(SHOTS_JSON, `${JSON.stringify(known, null, 2)}\n`);
}

async function headers(page: Page): Promise<Record<string, string>> {
  const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

  return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

async function api(page: Page, method: 'post' | 'patch' | 'put', path: string, data: unknown = {}): Promise<unknown> {
  const response = await page.request[method](`/api/v1${path}`, { headers: await headers(page), data });
  expect(response.status(), `${method} ${path}: ${await response.text()}`).toBeLessThan(300);

  return (await response.json().catch(() => ({}))) as unknown;
}

/** Clicks until the thing it opens is there: a click before the page is interactive does nothing. */
async function openWithRetry(page: Page, clickId: string, shownId: string): Promise<void> {
  await expect(async () => {
    await page.getByTestId(clickId).click();
    await expect(page.getByTestId(shownId)).toBeVisible({ timeout: 3_000 });
  }).toPass({ timeout: 30_000 });
}

/** A navigation that another one interrupts (a client redirect) is tried again. */
async function go(page: Page, url: string): Promise<void> {
  for (let attempt = 1; ; attempt += 1) {
    try {
      await page.goto(url);

      return;
    } catch (error) {
      if (attempt >= 3 || !/ERR_ABORTED|interrupted/i.test(String(error))) throw error;
      await page.waitForTimeout(700);
    }
  }
}

async function newPage(browser: Browser, viewport = { width: 1280, height: 800 }): Promise<Page> {
  const context = await browser.newContext({
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
    viewport,
    baseURL: process.env.BASE_URL ?? 'http://localhost:8080',
  });

  return context.newPage();
}

const INSTITUTION = `Collège Saint-Louis ${Date.now().toString(36).slice(-3)}`;

test('walks every role through every screen and takes the pictures of the manuals', async ({ browser }) => {
  test.setTimeout(30 * 60_000);

  // ---------------------------------------------------------------- Owner: register, verify, institution, team
  const owner = await newPage(browser);
  await go(owner, '/register');
  await owner.getByTestId('register-institution-name').fill(INSTITUTION);
  await owner.getByTestId('register-name').fill('Marie Joseph');
  const ownerEmail = uniqueEmail('directrice');
  await owner.getByTestId('register-email').fill(ownerEmail);
  await owner.getByTestId('register-password').fill(PASSWORD);
  await shoot(owner, 'owner-register', [
    'register-institution-name',
    'register-name',
    'register-email',
    'register-password',
    'register-submit',
  ]);
  await owner.getByTestId('register-submit').click();
  await expect(owner).toHaveURL(/\/admin$/);
  await shoot(owner, 'owner-verify', ['verify-banner', 'resend-button', 'dashboard-welcome']);
  await verifyEmail(owner, ownerEmail);

  await go(owner, '/admin/institution');
  await expect(owner.getByTestId('institution-page')).toBeVisible();
  await shoot(owner, 'owner-institution', ['profile-name', 'profile-type', 'logo-change', 'profile-save', 'public-address']);

  // The team: an invitation, the pending card, and the manager who accepts.
  const managerEmail = uniqueEmail('secretaire');
  await go(owner, '/admin/institution');
  await owner.getByTestId('invite-open').click();
  await owner.getByTestId('invite-email').fill(managerEmail);
  await owner.getByTestId('invite-role').selectOption('manager');
  await shoot(owner, 'owner-team', ['invite-email', 'invite-role', 'invite-submit']);
  await owner.getByTestId('invite-submit').click();
  await expect(owner.locator('[data-testid^="invitation-email-"]', { hasText: managerEmail })).toBeVisible();
  await shoot(owner, 'owner-invitation', ['invitation-card-1', 'invitation-status-1', 'invitation-cancel-1', 'users-card']);
  const [invitation] = await waitForMails(managerEmail, 1);
  const managerLink = linkIn(invitation!, '/accept-invitation');
  if (managerLink === null) throw new Error('No invitation link in the email');

  // ---------------------------------------------------------------- The sign-in page, seen by a visitor
  const visitor = await newPage(browser);
  await go(visitor, '/login');
  await shoot(visitor, 'admin-login', ['login-email', 'login-password', 'login-submit', 'login-forgot', 'login-register']);
  await go(visitor, '/forgot-password');
  await expect(visitor.getByTestId('forgot-form')).toBeVisible();
  await shoot(visitor, 'admin-forgot', ['forgot-email', 'forgot-submit']);
  await visitor.close();

  // ---------------------------------------------------------------- An election, set up the way a person does it
  const startsLater = new Date(Date.now() + 3 * 3600_000).toISOString();
  const ends = new Date(Date.now() + 2 * 24 * 3600_000).toISOString();

  await go(owner, '/admin/elections/new');
  await shoot(owner, 'admin-election-new', [
    'election-title',
    'election-description',
    'election-language',
    'election-save',
  ]);

  const election = await createElection(owner, 'Élection du conseil des élèves', startsLater, ends);
  await go(owner, `/admin/elections/${election.id}`);
  await expect(owner.getByTestId('election-page')).toBeVisible();
  await shoot(owner, 'admin-election', ['election-hero', 'election-card-steps', 'election-checklist', 'election-edit']);

  // Ballots and parties
  const party = await createParty(owner, election.id, 'Ensemble pour l’école', '#5468D4', 'EPE');
  await createParty(owner, election.id, 'Voix des élèves', '#D4572F', 'VDE');
  const president = await createBallot(owner, election.id, 'Président(e) du conseil', { seats: 1, allow_blank: true });
  const delegate = await createBallot(owner, election.id, 'Délégué(e) de classe', {
    seats: 1,
    allow_blank: false,
    description: 'Seuls les élèves de la 4e année votent à ce scrutin.',
  });
  const commission = await createBallot(owner, election.id, 'Commission des fêtes', { seats: 2, allow_blank: false });

  await createCandidate(owner, president.id, 'Nadège', 'Pierre-Louis', { slogan: 'Une école qui nous écoute', party: party.id });
  await createCandidate(owner, president.id, 'Jean-Marc', 'Désir', { slogan: 'Plus de sport', sex: 'male' });
  await createCandidate(owner, delegate.id, 'Ricardo', 'Saint-Fleur', { sex: 'male' });
  await createCandidate(owner, delegate.id, 'Marie', 'Augustin');
  for (const [first, last] of [
    ['Sandra', 'Louis'],
    ['Kevin', 'Baptiste'],
    ['Esther', 'Dorvil'],
    ['Wilson', 'Exantus'],
  ] as const) {
    await createCandidate(owner, commission.id, first, last);
  }

  await go(owner, `/admin/elections/${election.id}/ballots`);
  await expect(owner.getByTestId('ballots-page')).toBeVisible();
  await shoot(owner, 'admin-ballots', ['ballots-add', 'ballot-card-1', 'ballot-seats-1', 'ballot-scope-2', 'ballot-edit-1']);

  await openWithRetry(owner, 'candidate-add-1', 'candidate-modal');
  await owner.getByTestId('candidate-form-first-name').fill('Fabienne');
  await owner.getByTestId('candidate-form-last-name').fill('Jean-Baptiste');
  await owner.getByTestId('candidate-form-slogan').fill('Ensemble, plus loin');
  await shoot(owner, 'admin-candidates', [
    'candidate-form-first-name',
    'candidate-form-last-name',
    'candidate-form-slogan',
    'candidate-form-party',
    'candidate-form-photo-drop',
    'candidate-form-save',
  ]);
  await owner.keyboard.press('Escape');

  // Voters and groups
  const names: [string, string | null][] = [
    ['Wideline Joseph', '4e année'],
    ['Paul Étienne', '5e année'],
    ['Marc Sans-groupe', null],
    ['Rose-Anne Charles', '4e année'],
    ['Stanley Previl', '4e année'],
    ['Fabiola Mathurin', '4e année'],
    ['Jonathan Alexis', '5e année'],
    ['Lovely Cadet', '4e année'],
  ];
  const voters = [];
  for (const [name, group] of names) {
    voters.push(await createVoter(owner, election.id, name, group ? { group } : {}));
  }
  await go(owner, `/admin/elections/${election.id}/voters`);
  await expect(owner.getByTestId('voters-page')).toBeVisible();
  await shoot(owner, 'admin-voters', ['voter-add', 'voters-import', 'voters-search', 'voters-filter-all', 'groups-card', 'voters-copy']);

  await go(owner, `/admin/elections/${election.id}/voters/import`);
  await expect(owner.getByTestId('import-page')).toBeVisible();
  await shoot(owner, 'admin-import', ['import-instructions', 'import-template-csv', 'import-dropzone', 'import-upload']);

  // Codes
  await go(owner, `/admin/elections/${election.id}/codes`);
  await expect(owner.getByTestId('codes-page')).toBeVisible();
  await shoot(owner, 'admin-codes', ['codes-totals', 'codes-generate-all', 'codes-send', 'codes-lost']);

  const codes: string[] = [];
  for (const voter of voters) {
    const answer = (await api(owner, 'post', `/voters/${voter.id}/credential`)) as { data: { code: string } };
    codes.push(answer.data.code);
  }

  // Schedule: the checklist, then the election starts in 30 seconds so the scheduler opens it.
  const startsSoon = new Date(Date.now() + 40_000).toISOString();
  await api(owner, 'patch', `/elections/${election.id}`, { starts_at: startsSoon });
  await go(owner, `/admin/elections/${election.id}`);
  await expect(owner.getByTestId('election-schedule-button')).toBeVisible();
  await shoot(owner, 'admin-schedule', ['election-checklist', 'election-schedule-button', 'election-card-steps']);
  await api(owner, 'post', `/elections/${election.id}/schedule`, { accept_warnings: true });

  // ---------------------------------------------------------------- The voter, before and during the vote
  const voter = await newPage(browser);
  if ((await phaseOf(voter, election.id)) === 'before') {
    await go(voter, `/vote/${election.id}`);
    await shoot(voter, 'voter-before', ['vote-election-title', 'vote-countdown']);
  }
  await waitUntilOpen(voter, election.id);

  await go(voter, `/vote/${election.id}`);
  await expect(voter.getByTestId('vote-code-input')).toBeVisible();
  await shoot(voter, 'voter-code', ['vote-election-title', 'vote-code-input', 'vote-code-submit']);

  await voter.getByTestId('vote-code-input').fill('ZZZZZ-ZZZZZ');
  await voter.getByTestId('vote-code-submit').click();
  await expect(voter.getByTestId('vote-code-error')).toBeVisible();
  await shoot(voter, 'voter-code-error', ['vote-code-input', 'vote-code-error']);

  await go(voter, `/vote/${election.id}`);
  await voter.getByTestId('vote-code-input').fill(codes[0]!);
  await voter.getByTestId('vote-code-submit').click();
  await expect(voter.getByTestId('vote-identity-name')).toBeVisible();
  await shoot(voter, 'voter-identity', [
    'vote-identity-name',
    'vote-identity-group',
    'vote-identity-ballot-1',
    'vote-identity-yes',
    'vote-identity-no',
  ]);
  await voter.getByTestId('vote-identity-yes').click();

  await expect(voter.getByTestId('vote-ballot-title')).toBeVisible();
  await voter.getByTestId('vote-candidate-1').click();
  await shoot(voter, 'voter-ballot', ['vote-ballot-progress', 'vote-candidate-1', 'vote-candidate-2', 'vote-blank', 'vote-ballot-next']);
  await voter.getByTestId('vote-blank').click();
  await shoot(voter, 'voter-ballot-blank', ['vote-blank', 'vote-ballot-hint', 'vote-ballot-next']);
  await voter.getByTestId('vote-blank').click();
  await voter.getByTestId('vote-candidate-2').click();
  await voter.getByTestId('vote-ballot-next').click();

  await expect(voter.getByTestId('vote-ballot-title')).toHaveText('Délégué(e) de classe');
  await voter.getByTestId('vote-candidate-1').click();
  await voter.getByTestId('vote-ballot-next').click();

  await expect(voter.getByTestId('vote-ballot-title')).toHaveText('Commission des fêtes');
  await voter.getByTestId('vote-candidate-1').click();
  await voter.getByTestId('vote-candidate-3').click();
  await shoot(voter, 'voter-ballot-several', ['vote-ballot-hint', 'vote-candidate-1', 'vote-candidate-3', 'vote-ballot-back', 'vote-ballot-next']);
  await voter.getByTestId('vote-ballot-next').click();

  await expect(voter.getByTestId('vote-review')).toBeVisible();
  await shoot(voter, 'voter-review', ['vote-review-ballot-1', 'vote-review-edit-1', 'vote-confirm']);
  await voter.getByTestId('vote-confirm').click();
  await expect(voter.getByTestId('vote-done')).toBeVisible();
  await shoot(voter, 'voter-done', ['vote-done', 'vote-done-time']);
  await voter.close();

  // The same code again.
  const again = await newPage(browser);
  await go(again, `/vote/${election.id}`);
  await again.getByTestId('vote-code-input').fill(codes[0]!);
  await again.getByTestId('vote-code-submit').click();
  await expect(again.getByTestId('vote-code-error')).toBeVisible();
  await shoot(again, 'voter-already', ['vote-code-error']);
  await again.close();

  // A voter on a phone.
  const phone = await newPage(browser, { width: 390, height: 844 });
  await go(phone, `/vote/${election.id}#${codes[3]}`);
  await expect(phone.getByTestId('vote-identity-name')).toBeVisible();
  await phone.getByTestId('vote-identity-yes').click();
  await expect(phone.getByTestId('vote-ballot-title')).toBeVisible();
  await phone.getByTestId('vote-candidate-1').click();
  await shoot(phone, 'voter-phone-ballot', ['vote-candidate-1', 'vote-candidate-2', 'vote-ballot-next']);
  await phone.close();

  // Other people vote, so the pages of the organiser have something to show.
  await castVoteFor(browser, election.id, codes[1]!, 1);
  await castVoteFor(browser, election.id, codes[4]!, 0);
  await castVoteFor(browser, election.id, codes[5]!, 0);

  // ---------------------------------------------------------------- Manager: the same screens, with fewer powers
  const invitee = await newPage(browser);
  await go(invitee, managerLink);
  await expect(invitee.getByTestId('accept-form')).toBeVisible();
  await shoot(invitee, 'admin-accept', ['accept-name', 'accept-password', 'accept-submit']);
  await invitee.context().close();
  const manager = await acceptInNewBrowser(browser, managerLink, 'Jean Pierre');
  await go(manager, '/admin');
  await shoot(manager, 'admin-dashboard', [
    'dashboard-welcome',
    'dashboard-quick-new-election',
    'dashboard-card-open-election',
    'menu-link-elections',
    'user-menu',
  ]).catch(() => undefined);
  await go(manager, '/admin/institution');
  await shoot(manager, 'manager-institution', ['users-manager-note', 'profile-card']);
  await manager.context().close();

  // ---------------------------------------------------------------- Turnout while open
  await go(owner, `/admin/elections/${election.id}/turnout`);
  await expect(owner.getByTestId('turnout-page')).toBeVisible();
  await shoot(owner, 'admin-turnout', [
    'turnout-percentage',
    'turnout-chart-card',
    'turnout-groups-card',
    'nonvoters-card',
    'nonvoters-export-csv',
  ]);

  // ---------------------------------------------------------------- Platform admin: list, suspend, voter sees "paused"
  const platform = await newPage(browser);
  await go(platform, '/login');
  await shoot(platform, 'platform-login', ['login-email', 'login-password', 'login-submit']);
  await signInAs(platform, ADMIN_EMAIL, ADMIN_PASSWORD);
  await expect(platform).toHaveURL(/\/platform$/);
  await findInstitution(platform, INSTITUTION);
  await shoot(platform, 'platform-list', [
    'platform-search',
    'platform-filter-status',
    'platform-name-1',
    'platform-suspend-1',
    'platform-impersonate-1',
  ]);
  await platform.getByTestId('platform-suspend-1').click();
  await platform.getByTestId('suspend-reason').fill('Vérification demandée par l’établissement.');
  await shoot(platform, 'platform-suspend', ['suspend-reason', 'suspend-confirm']);
  await platform.getByTestId('suspend-confirm').click();
  await expect(platform.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'suspended');
  await shoot(platform, 'platform-suspended', ['platform-status-1', 'platform-reactivate-1']);

  // What the institution's people and its voters see meanwhile.
  const paused = await newPage(browser);
  await go(paused, `/vote/${election.id}`);
  await expect(paused.getByTestId('vote-paused')).toBeVisible();
  await shoot(paused, 'voter-paused', ['vote-paused']);
  await paused.close();
  const refused = await newPage(browser);
  await signInAs(refused, ownerEmail, PASSWORD);
  await expect(refused.getByTestId('login-suspended')).toBeVisible();
  await shoot(refused, 'login-suspended', ['login-suspended', 'login-suspended-reason']);
  await refused.context().close();

  await platform.getByTestId('platform-reactivate-1').click();
  await platform.getByTestId('reactivate-confirm').click();
  await expect(platform.getByTestId('platform-status-1')).toHaveAttribute('data-status', 'active');

  // Act as the owner, to help.
  await platform.getByTestId('platform-impersonate-1').click();
  await expect(platform.getByTestId('impersonation-banner')).toBeVisible();
  await shoot(platform, 'platform-impersonate', ['impersonation-banner', 'impersonation-end']);
  await platform.getByTestId('impersonation-end').click();
  await platform.close();

  // A suspension ends the open sessions: the owner signs in again.
  await signInAs(owner, ownerEmail, PASSWORD);
  await expect(owner).toHaveURL(/\/admin$/);

  // ---------------------------------------------------------------- Close, results, publication
  await go(owner, `/admin/elections/${election.id}`);
  await openWithRetry(owner, 'election-close-button', 'close-dialog');
  await owner.getByTestId('close-confirmation').fill('Élection du conseil des élèves');
  await shoot(owner, 'admin-close', ['close-dialog', 'close-confirmation', 'close-confirm', 'close-cancel']);
  await owner.keyboard.press('Escape');
  await closeNow(owner, election.id, 'Élection du conseil des élèves');

  const afterClose = await newPage(browser);
  await go(afterClose, `/vote/${election.id}`);
  await shoot(afterClose, 'voter-closed', ['vote-closed', 'vote-election-title']);
  await afterClose.close();

  await go(owner, `/admin/elections/${election.id}/results`);
  await expect(owner.getByTestId('results-page')).toBeVisible();
  await shoot(
    owner,
    'admin-results',
    ['results-chip-turnout', 'result-winner-1-1', 'result-check-1', 'result-note-1', 'publication-card'],
    { full: true },
  );

  await openWithRetry(owner, 'publish-button', 'publish-dialog');
  await shoot(owner, 'admin-publication', ['publish-dialog', 'publish-confirm', 'publish-cancel']);
  await owner.getByTestId('publish-confirm').click();
  await expect(owner.getByTestId('public-link')).toBeVisible();
  await shoot(owner, 'admin-published', ['public-link', 'report-download', 'unpublish-button']);

  // Public pages, as a visitor
  const publicVisitor = await newPage(browser);
  await go(publicVisitor, `/results/${election.id}`);
  await expect(publicVisitor.getByTestId('public-results-page')).toBeVisible();
  await shoot(publicVisitor, 'public-results', ['public-turnout', 'public-winner-1-1', 'public-share', 'public-report-download'], { full: true });
  await publicVisitor.close();

  // ---------------------------------------------------------------- Audit log, account, two-factor
  await go(owner, '/admin/audit');
  await expect(owner.getByTestId('audit-page')).toBeVisible();
  await shoot(owner, 'owner-audit', ['audit-filter-user', 'audit-filter-election', 'audit-list', 'audit-export-csv']);

  await go(owner, '/admin/institution');
  await shoot(owner, 'owner-public', ['public-address', 'public-address-copy', 'public-address-open']);

  await go(owner, '/admin/account');
  await expect(owner.getByTestId('account-page')).toBeVisible();
  await shoot(owner, 'admin-account', ['change-password-card', 'security-card', 'two-factor-setup-open']);
  await owner.getByTestId('two-factor-setup-open').click();
  await owner.getByTestId('two-factor-password').fill(PASSWORD);
  await owner.getByTestId('two-factor-setup-submit').click();
  await expect(owner.getByTestId('two-factor-secret')).toBeVisible();
  await shoot(owner, 'owner-twofactor', ['two-factor-qr', 'two-factor-secret', 'two-factor-code', 'two-factor-confirm']);

  expect(missing, `markers without a target:\n${missing.join('\n')}`).toEqual([]);
});

// Sanity: the helper used above exists.
void readGroups;
