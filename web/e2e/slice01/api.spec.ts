import { expect, test } from '@playwright/test';
import { UUID_V4 } from '../support/checks';

/*
 * Slice 01 — the API reached through the proxy, on the same origin as the pages.
 * docs/api/system/GET-health.md and docs/api/README.md.
 */

test('the API answers on the same origin as the pages [NFR-OPS-01]', async ({ request }) => {
  const response = await request.get('/api/v1/health');

  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/json');
  expect(response.headers()['x-request-id']).toMatch(UUID_V4);

  const body = await response.json();
  expect(body.data.status).toBe('ok');
  expect(body.data.checks).toEqual({ database: 'ok', redis: 'ok' });
});

test('an unknown API path answers 404 as JSON, not a web page [NFR-SEC-01]', async ({ request }) => {
  const response = await request.get('/api/v1/nothing-here');

  expect(response.status()).toBe(404);
  expect((await response.json()).error.code).toBe('not_found');
});

test('an unknown page answers 404 [NFR-OPS-01]', async ({ page }) => {
  const response = await page.goto('/nothing-here');

  expect(response!.status()).toBe(404);
});

test('answers do not say what software runs the site [NFR-SEC-01]', async ({ request }) => {
  for (const path of ['/', '/api/v1/health']) {
    const headers = (await request.get(path)).headers();

    expect(headers['x-powered-by'], `${path}: X-Powered-By`).toBeUndefined();
    expect(headers['server'] ?? '', `${path}: Server`).not.toMatch(/\d/);
    expect(headers['x-content-type-options'], `${path}: X-Content-Type-Options`).toBe('nosniff');
    expect(headers['referrer-policy'], `${path}: Referrer-Policy`).toBeTruthy();
  }
});
