import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { PREVIEW_COLORS } from './previewColors';

describe('preview image colours', () => {
  const tokens = readFileSync(
    join(import.meta.dirname, '..', '..', 'styles', 'tokens.css'),
    'utf8',
  );

  it('are the values of the design tokens', () => {
    for (const [name, value] of Object.entries(PREVIEW_COLORS)) {
      const match = new RegExp(`--color-${name}:\\s*(#[0-9a-fA-F]{6})`).exec(tokens);

      expect(match?.[1]?.toLowerCase(), name).toBe(value);
    }
  });
});
