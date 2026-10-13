import { expect, test } from '@playwright/test';
import { registerAndEnter } from '../support/admin';

/*
 * Slice 19 (docs/slices/19-security-hardening.md "Rules"): the web application's Content-Security-Policy, with a nonce
 * per request, and no page broken by it.
 */

const UNKNOWN = '3f1c0c1e-8a54-4c5e-9b7b-2d0f0c9a51aa';

function policyOf(header: string | undefined): Record<string, string> {
  const out: Record<string, string> = {};
  for (const part of (header ?? '').split(';')) {
    const [name, ...rest] = part.trim().split(/\s+/);
    if (name) out[name] = rest.join(' ');
  }
  return out;
}

test.describe('web security headers [NFR-SEC-04]', () => {
  for (const path of ['/', '/login', '/register', `/vote/${UNKNOWN}`, `/results/${UNKNOWN}`]) {
    test(`${path} has a strict Content-Security-Policy with a nonce`, async ({ request }) => {
      const response = await request.get(path);
      const policy = policyOf(response.headers()['content-security-policy']);

      expect(policy['default-src']).toBe("'self'");
      expect(policy['script-src']).toMatch(/'nonce-[A-Za-z0-9+/=_-]{16,}'/);
      expect(policy['script-src']).not.toContain("'unsafe-inline'");
      expect(policy['script-src']).not.toContain("'unsafe-eval'");
      expect(policy['frame-ancestors']).toBe("'none'");
      expect(policy['object-src']).toBe("'none'");
      expect(policy['base-uri']).toBe("'none'");
      expect(policy['form-action']).toBe("'self'");
      expect(response.headers()['x-content-type-options']).toBe('nosniff');
      expect(response.headers()['referrer-policy']).toBe('strict-origin-when-cross-origin');
      expect(response.headers()['permissions-policy']).toContain('camera=()');
    });
  }

  test('the nonce changes with every request', async ({ request }) => {
    const nonce = async () =>
      /'nonce-([^']+)'/.exec((await request.get('/login')).headers()['content-security-policy'] ?? '')?.[1];

    const [a, b] = [await nonce(), await nonce()];

    expect(a).toBeTruthy();
    expect(a).not.toBe(b);
  });

  test('the main pages run with no Content-Security-Policy violation', async ({ page }) => {
    // The development server's own scripts (`/_next/static`, React's debug `eval`, HMR style tags without a
    // nonce) break a strict policy and are not in a production build; a violation from our pages still counts.
    const violations: string[] = [];
    await page.exposeFunction('reportViolation', (text: string) => violations.push(text));
    await page.addInitScript(() => {
      document.addEventListener('securitypolicyviolation', (event) => {
        if (event.sourceFile.includes('/_next/static/')) return;
        void (window as unknown as { reportViolation: (t: string) => void }).reportViolation(
          `${event.violatedDirective} ${event.sourceFile}:${event.lineNumber} ${event.sample}`,
        );
      });
    });

    await page.goto('/');
    await page.goto('/login');
    await registerAndEnter(page);
    for (const path of ['/admin', '/admin/elections', '/admin/institution', '/admin/audit', '/admin/elections/new']) {
      await page.goto(path);
      await expect(page.getByTestId('top-bar-title')).toBeVisible();
    }

    expect(violations).toEqual([]);
  });

  test('the API answers carry the hardening headers through the proxy', async ({ request }) => {
    const response = await request.get('/api/v1/health');

    expect(response.headers()['x-content-type-options']).toBe('nosniff');
    expect(response.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(response.headers()['cache-control']).toContain('no-store');
    expect(response.headers()['permissions-policy']).toContain('camera=()');
    expect(response.headers()['server']).toBeUndefined();
  });
});
