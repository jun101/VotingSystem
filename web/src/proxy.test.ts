import { describe, expect, it } from 'vitest';
import { config, contentSecurityPolicy } from './proxy';

/*
 * The proxy overwrites the internal headers (`x-admin-area`, `x-vote-election`, `x-public-election`).
 * A path it does not run on would carry the visitor's own values to `getI18n`, so the matcher must
 * cover every path with a page, dots included.
 */
describe('proxy matcher', () => {
  const pattern = new RegExp(`^${config.matcher[0]}$`);

  it('runs on pages, including paths with a dot', () => {
    for (const path of ['/', '/admin/elections', '/results/abc', '/results/abc.def', '/x.y']) {
      expect(pattern.test(path), path).toBe(true);
    }
  });

  it('does not run on the build files and the API', () => {
    for (const path of ['/_next/static/a.js', '/api/v1/health']) {
      expect(pattern.test(path), path).toBe(false);
    }
  });
});

describe('contentSecurityPolicy', () => {
  it('allows scripts only from the site and the nonce, never inline or eval', () => {
    const policy = contentSecurityPolicy('abc123', { NODE_ENV: 'production' });
    expect(policy).toContain("script-src 'self' 'nonce-abc123' 'strict-dynamic'");
    expect(policy).not.toContain("'unsafe-eval'");
    expect(policy).toContain("frame-ancestors 'none'");
    expect(policy).toContain("object-src 'none'");
  });

  it('adds unsafe-eval only outside production and only when asked', () => {
    const dev = {
      NODE_ENV: 'development',
      CSP_DEV_UNSAFE_EVAL: '1',
    } as unknown as NodeJS.ProcessEnv;
    const prod = {
      NODE_ENV: 'production',
      CSP_DEV_UNSAFE_EVAL: '1',
    } as unknown as NodeJS.ProcessEnv;
    expect(contentSecurityPolicy('n', dev)).toContain("'unsafe-eval'");
    expect(contentSecurityPolicy('n', prod)).not.toContain("'unsafe-eval'");
  });
});
