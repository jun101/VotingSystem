import { expect, test, type Browser, type Page } from '@playwright/test';
import { expectAccessible, expectNoSidewaysScroll } from '../support/checks';
import { castVoteFor, closeNow } from '../support/results';
import { prepareTurnout, type TurnoutElection } from '../support/turnout';
import { waitUntilOpen } from '../support/voting';

/*
 * Slice 16 — publication (A13) and the public pages (M-results, institution). docs/slices/16-publication.md.
 * API: docs/api/publication/.
 *
 * One election opened by the scheduler; six voters, four choose the first candidate (sorted by name), two the second.
 * Closed by the organiser, then published, looked at as a visitor, switched to "winners only", unpublished, archived.
 */

test.describe.configure({ mode: 'serial' });

let data: TurnoutElection;
let institution = '';

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  data = await prepareTurnout(browser, 25);
});

async function organiser(browser: Browser, path: string): Promise<Page> {
  const context = await browser.newContext({
    storageState: data.storageState,
    locale: 'fr-FR',
    timezoneId: 'America/Port-au-Prince',
  });
  const page = await context.newPage();
  await page.goto(path);

  return page;
}

async function visitor(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ locale: 'fr-FR', timezoneId: 'America/Port-au-Prince' });

  return context.newPage();
}

test('waits for the scheduler to open the election [FR-ELEC-03]', async ({ page }) => {
  test.setTimeout(200_000);

  await waitUntilOpen(page, data.election);
});

test('closes the election after six voters have voted, four for the first candidate [FR-RES-02]', async ({ browser }) => {
  const keys = ['ada', 'bob', 'cleo', 'fay', 'dan', 'eli'] as const;
  for (const [index, key] of keys.entries()) {
    await castVoteFor(browser, data.election, data.codes[key], index < 4 ? 0 : 1);
  }
  const page = await organiser(browser, `/admin/elections/${data.election}`);
  await closeNow(page, data.election, data.title);
});

test('shows no public page before publication, and the organiser sees a closed election ready to publish [FR-RES-04]', async ({ browser }) => {
  const guest = await visitor(browser);
  const response = await guest.goto(`/results/${data.election}`);

  expect(response?.status()).toBe(404);
  await expect(guest.getByTestId('public-unavailable')).toBeVisible();
  await expect(guest.getByTestId('public-results-page')).toHaveCount(0);

  const page = await organiser(browser, `/admin/elections/${data.election}/results`);
  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'closed');
  await expect(page.getByTestId('report-download')).toBeVisible();

  // Every text of the page is translated: a missing namespace shows the key itself ("publication.title").
  const text = await page.getByTestId('results-page').innerText();
  expect(text).not.toMatch(/\b(publication|results|elections|app|admin)\.[a-zA-Z]+(\.[a-zA-Z]+)*\b/);

  // From 1280 px the posts keep two thirds of the width and the publication card the right third.
  if ((page.viewportSize()?.width ?? 0) >= 1280) {
    const zone = await page.getByTestId('results-zone').boundingBox();
    const rail = await page.getByTestId('publication-card').boundingBox();

    expect(zone && rail).toBeTruthy();
    expect(zone!.x).toBeLessThan(rail!.x);
    expect(zone!.width).toBeGreaterThan(rail!.width * 1.6);
  }
});

test('publishes with the full display and gives the address of the public page [FR-RES-04, FR-RES-08]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);

  await page.getByTestId('publish-button').click();
  await expect(page.getByTestId('publish-dialog')).toBeVisible();
  await page.getByTestId('publish-display-full').check();
  await page.getByTestId('publish-confirm').click();

  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'published');
  await expect(page.getByTestId('public-link')).toHaveAttribute('href', `/results/${data.election}`);
});

test('shows a visitor the results without signing in: title, institution, turnout, counts and the winner [FR-RES-04, FR-RES-02]', async ({ browser }) => {
  const guest = await visitor(browser);
  const response = await guest.goto(`/results/${data.election}`);

  expect(response?.status()).toBe(200);
  await expect(guest.getByTestId('public-results-page')).toBeVisible();
  await expect(guest.getByTestId('public-election-title')).toHaveText(data.title);
  await expect(guest.getByTestId('public-institution')).toContainText('Collège');
  await expect(guest.getByTestId('public-turnout')).toContainText('6');
  await expect(guest.getByTestId('public-ballot-1')).toContainText('Président(e)');
  await expect(guest.getByTestId('public-votes-1-1')).toContainText('4');
  await expect(guest.getByTestId('public-winner-1-1')).toBeVisible();
  await expect(guest.getByTestId('public-tie-1')).toHaveCount(0);

  await expectNoSidewaysScroll(guest);
  await expectAccessible(guest);
});

test('has sharing metadata, share buttons and a preview image of 1200 by 630 [FR-RES-05]', async ({ browser }) => {
  const guest = await visitor(browser);
  await guest.goto(`/results/${data.election}`);

  const meta = async (property: string) =>
    guest.locator(`meta[property="${property}"], meta[name="${property}"]`).first().getAttribute('content');
  expect(await meta('og:title')).toContain(data.title);
  expect(await meta('og:description')).toBeTruthy();
  expect(await meta('og:image')).toContain(`/results/${data.election}/opengraph-image`);
  expect(await meta('twitter:card')).toBe('summary_large_image');

  const share = `/results/${data.election}`;
  await expect(guest.getByTestId('public-share-whatsapp')).toHaveAttribute('href', /wa\.me|whatsapp/);
  await expect(guest.getByTestId('public-share-whatsapp')).toHaveAttribute('href', new RegExp(encodeURIComponent(share).replace(/%2F/g, '(%2F|/)')));
  await expect(guest.getByTestId('public-share-facebook')).toHaveAttribute('href', /facebook\.com/);
  await expect(guest.getByTestId('public-share-x')).toHaveAttribute('href', /(x|twitter)\.com/);
  await expect(guest.getByTestId('public-share-copy')).toBeVisible();

  const image = await guest.request.get(`/results/${data.election}/opengraph-image`);
  expect(image.status()).toBe(200);
  expect(image.headers()['content-type']).toContain('image/png');
  const png = await image.body();
  expect(png.subarray(1, 4).toString('ascii')).toBe('PNG');
  expect(png.readUInt32BE(16)).toBe(1200);
  expect(png.readUInt32BE(20)).toBe(630);
  await expect(guest.getByTestId('public-image-download')).toBeVisible();
});

test('lets anyone download the PDF report [FR-RES-06]', async ({ browser }) => {
  const guest = await visitor(browser);
  await guest.goto(`/results/${data.election}`);

  const href = await guest.getByTestId('public-report-download').getAttribute('href');
  expect(href).toBeTruthy();
  const response = await guest.request.get(href!);
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/pdf');
  expect((await response.body()).subarray(0, 4).toString('ascii')).toBe('%PDF');
});

test('lists the published election on the institution page [FR-RES-07]', async ({ browser }) => {
  const guest = await visitor(browser);
  const info = await guest.request.get(`/api/v1/public/elections/${data.election}/results`);
  institution = ((await info.json()) as { data: { institution: { id: string } } }).data.institution.id;

  const response = await guest.goto(`/institutions/${institution}`);

  expect(response?.status()).toBe(200);
  await expect(guest.getByTestId('institution-page')).toBeVisible();
  await expect(guest.getByTestId('institution-name')).toContainText('Collège');
  await expect(guest.getByTestId('institution-election-1')).toContainText(data.title);
  await guest.getByTestId('institution-election-1').click();
  await expect(guest).toHaveURL(`/results/${data.election}`);
  await expectAccessible(guest);
});

test('shows only turnout and winners when the election is republished with the winners display [FR-RES-08]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);
  await page.getByTestId('unpublish-button').click();
  await page.getByTestId('unpublish-confirm').click();
  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'closed');

  await page.getByTestId('publish-button').click();
  await page.getByTestId('publish-display-winners').check();
  await page.getByTestId('publish-confirm').click();
  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'published');

  const guest = await visitor(browser);
  await guest.goto(`/results/${data.election}`);
  await expect(guest.getByTestId('public-winner-1-1')).toBeVisible();
  await expect(guest.getByTestId('public-turnout')).toBeVisible();
  await expect(guest.locator('[data-testid^="public-votes-"]')).toHaveCount(0);
});

test('takes the page down when unpublished, and archiving keeps it down [FR-ELEC-03]', async ({ browser }) => {
  const page = await organiser(browser, `/admin/elections/${data.election}/results`);
  await page.getByTestId('unpublish-button').click();
  await page.getByTestId('unpublish-confirm').click();
  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'closed');

  const guest = await visitor(browser);
  const response = await guest.goto(`/results/${data.election}`);
  expect(response?.status()).toBe(404);
  await expect(guest.getByTestId('public-unavailable')).toBeVisible();

  await page.getByTestId('archive-button').click();
  await expect(page.getByTestId('publication-card')).toHaveAttribute('data-status', 'archived');
});
