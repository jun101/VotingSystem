import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/*
 * Two rules of the front end, checked on every source file of the components and the
 * app: no raw colour, and no text of our own written in a component.
 */

const root = join(import.meta.dirname, '..', '..');

function sources(directory: string): string[] {
  return readdirSync(directory).flatMap((name) => {
    const path = join(directory, name);

    if (statSync(path).isDirectory()) return sources(path);

    return /\.tsx?$/.test(name) && !/\.test\.tsx?$/.test(name) ? [path] : [];
  });
}

const files = [...sources(join(root, 'components')), ...sources(join(root, 'app'))];

describe('front-end rules', () => {
  it('finds the files to check', () => {
    expect(files.length).toBeGreaterThan(5);
  });

  it('writes no raw colour in a component or a page', () => {
    const raw = [
      /#[0-9a-fA-F]{3,8}\b/,
      /\b(rgb|rgba|hsl|hsla|oklch)\(/,
      /\b(bg|text|border|ring|outline|fill|stroke|from|to|via|divide|decoration)-(white|black|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/,
      /\b(bg|text|border|ring|outline|fill|stroke)-(white|black)\b/,
    ];

    for (const file of files) {
      const text = readFileSync(file, 'utf8');

      for (const pattern of raw) {
        expect(text, `${file} matches ${pattern}`).not.toMatch(pattern);
      }
    }
  });

  it('keeps the tokens in one place', () => {
    const globals = readFileSync(join(root, 'app', 'globals.css'), 'utf8');

    expect(globals).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
  });
});
