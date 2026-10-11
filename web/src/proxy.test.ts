import { describe, expect, it } from 'vitest';
import { config } from './proxy';

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
