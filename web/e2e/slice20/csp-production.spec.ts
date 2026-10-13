import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { registerAndEnter } from '../support/admin';
import { createBallot } from '../support/ballots';
import { createCandidate } from '../support/candidates';
import { createElection } from '../support/elections';
import { createVoter } from '../support/voters';
import { closeNow } from '../support/results';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 20, go-live check: the production build of the web application, served by the production images, shows every kind
 * of page with no Content-Security-Policy violation and no script error. On demand, against a production stack:
 *
 *   PROD_CSP=1 BASE_URL=http://127.0.0.1:8081 [PROD_REFERER=<the site's SITE_URL>] \
 *     npx playwright test e2e/slice20/csp-production.spec.ts --project=desktop
 *
 * (the development build needs `unsafe-eval` and would fail the same pages for another reason). Counts every
 * `securitypolicyviolation` event and every console error that mentions the policy.
 */

test.describe.configure({ mode: 'serial' });
test.skip(!process.env.PROD_CSP, 'on demand: PROD_CSP=1 against a production stack');

const violations: string[] = [];
const seen = new Set<string>();

function watch(page: Page, label: string): void {
  page.on('console', (message) => {
    const text = message.text();
    if (message.type() === 'error' && /content security policy|refused to (load|execute|apply|connect|frame)/i.test(text)) {
      violations.push(`${label}: ${text}`);
    }
  });
  page.on('pageerror', (error) => violations.push(`${label}: page error: ${error.message}`));
  void page.addInitScript(() => {
    document.addEventListener('securitypolicyviolation', (event) => {
      (window as unknown as { __csp: string[] }).__csp ??= [];
      (window as unknown as { __csp: string[] }).__csp.push(`${event.violatedDirective} blocked ${event.blockedURI}`);
    });
  });
}

async function collect(page: Page, label: string): Promise<void> {
  seen.add(label);
  await page.waitForTimeout(600);
  const found = await page.evaluate(() => (window as unknown as { __csp?: string[] }).__csp ?? []);
  for (const item of found) violations.push(`${label}: ${item}`);
}

async function visit(page: Page, path: string, label: string, ready?: string): Promise<void> {
  await page.goto(path);
  if (ready) await expect(page.getByTestId(ready)).toBeVisible();
  await collect(page, label);
}

async function context(browser: Browser): Promise<BrowserContext> {
  // Sanctum treats a request as the application's own when its Referer is a stateful domain (the site's address);
  // the helpers' direct API calls carry none, so give them the site's address (PROD_REFERER, default BASE_URL).
  const referer = process.env.PROD_REFERER ?? process.env.BASE_URL ?? 'http://localhost:8080';

  return browser.newContext({
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
    extraHTTPHeaders: { Referer: referer.endsWith('/') ? referer : `${referer}/` },
  });
}

test('every page of the production build runs under its policy', async ({ browser }) => {
  test.setTimeout(10 * 60_000);
  const owner = await (await context(browser)).newPage();
  watch(owner, 'owner');

  // Public pages and sign-up.
  await visit(owner, '/', 'home');
  await visit(owner, '/login', 'login', 'login-form');
  await visit(owner, '/register', 'register', 'register-form');
  await visit(owner, '/forgot-password', 'forgot-password', 'forgot-form');
  await visit(owner, '/guide/voter', 'guide-voter', 'guide-page');
  await visit(owner, '/nothing-here', 'not-found');

  // Organiser's area.
  await registerAndEnter(owner);
  await collect(owner, 'dashboard');
  await visit(owner, '/admin/elections', 'elections', 'elections-page');
  await visit(owner, '/admin/elections/new', 'election-new');
  await visit(owner, '/admin/institution', 'institution', 'institution-page');
  await visit(owner, '/admin/account', 'account', 'account-page');
  await visit(owner, '/admin/audit', 'audit', 'audit-page');
  await visit(owner, '/guide/owner', 'guide-owner', 'guide-page');

  const starts = new Date(Date.now() + 25_000).toISOString();
  const ends = new Date(Date.now() + 2 * 24 * 3600_000).toISOString();
  const election = await createElection(owner, 'Conseil des élèves', starts, ends);
  const ballot = await createBallot(owner, election.id, 'Président(e)', { seats: 1, allow_blank: true });
  await createCandidate(owner, ballot.id, 'Nadège', 'Pierre-Louis');
  await createCandidate(owner, ballot.id, 'Jean-Marc', 'Désir');
  const voter = await createVoter(owner, election.id, 'Wideline Joseph');
  const credential = await owner.request.post(`/api/v1/voters/${voter.id}/credential`, {
    headers: { 'X-XSRF-TOKEN': decodeURIComponent((await owner.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? ''), Accept: 'application/json' },
    data: {},
  });
  expect(credential.status()).toBe(201);
  const code = ((await credential.json()) as { data: { code: string } }).data.code;

  await visit(owner, `/admin/elections/${election.id}`, 'election', 'election-page');
  await visit(owner, `/admin/elections/${election.id}/ballots`, 'ballots', 'ballots-page');
  await owner.getByTestId('candidate-add-1').click();
  await expect(owner.getByTestId('candidate-modal')).toBeVisible();
  await collect(owner, 'candidate-modal');
  await owner.keyboard.press('Escape');
  await visit(owner, `/admin/elections/${election.id}/voters`, 'voters', 'voters-page');
  await visit(owner, `/admin/elections/${election.id}/voters/import`, 'import', 'import-page');
  await visit(owner, `/admin/elections/${election.id}/codes`, 'codes', 'codes-page');

  const token = decodeURIComponent((await owner.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value ?? '');
  const schedule = await owner.request.post(`/api/v1/elections/${election.id}/schedule`, {
    headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
    data: { accept_warnings: true },
  });
  expect(schedule.status()).toBe(200);

  // Voter.
  const guest = await (await context(browser)).newPage();
  watch(guest, 'voter');
  await guest.goto(`/vote/${election.id}`);
  await collect(guest, 'vote-before');
  await waitUntilOpen(guest, election.id);
  await guest.goto(`/vote/${election.id}`);
  await expect(guest.getByTestId('vote-code-input')).toBeVisible();
  await collect(guest, 'vote-code');
  await guest.getByTestId('vote-code-input').fill(code);
  await guest.getByTestId('vote-code-submit').click();
  await expect(guest.getByTestId('vote-identity-name')).toBeVisible();
  await collect(guest, 'vote-identity');
  await guest.getByTestId('vote-identity-yes').click();
  await expect(guest.getByTestId('vote-ballot-title')).toBeVisible();
  await guest.getByTestId('vote-candidate-1').click();
  await collect(guest, 'vote-ballot');
  await guest.getByTestId('vote-ballot-next').click();
  await expect(guest.getByTestId('vote-review')).toBeVisible();
  await collect(guest, 'vote-review');
  await guest.getByTestId('vote-confirm').click();
  await expect(guest.getByTestId('vote-done')).toBeVisible();
  await collect(guest, 'vote-done');

  // Organiser again: turnout, close, results, publish; then the public pages.
  await visit(owner, `/admin/elections/${election.id}/turnout`, 'turnout', 'turnout-page');
  await closeNow(owner, election.id, 'Conseil des élèves');
  await visit(owner, `/admin/elections/${election.id}/results`, 'results', 'results-page');
  await owner.getByTestId('publish-button').click();
  await expect(owner.getByTestId('publish-dialog')).toBeVisible();
  await collect(owner, 'publish-dialog');
  await owner.getByTestId('publish-confirm').click();
  await expect(owner.getByTestId('public-link')).toBeVisible();

  const visitor = await (await context(browser)).newPage();
  watch(visitor, 'visitor');
  await visit(visitor, `/results/${election.id}`, 'public-results', 'public-results-page');
  await visit(visitor, `/vote/${election.id}`, 'vote-closed');
  const profile = await owner.request.get('/api/v1/institution');
  const institution = ((await profile.json()) as { data: { id: string } }).data.id;
  await visit(visitor, `/institutions/${institution}`, 'public-institution', 'institution-page');

  expect(seen.size, 'pages visited').toBeGreaterThan(25);
  expect(violations, `CSP violations and script errors:\n${violations.join('\n')}`).toEqual([]);
});
