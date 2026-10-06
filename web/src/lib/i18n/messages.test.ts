import { describe, expect, it } from 'vitest';
import en from './messages/en.json';
import fr from './messages/fr.json';
import { getMessages, translate } from './messages';

function keysOf(node: unknown, prefix = ''): string[] {
  if (typeof node !== 'object' || node === null) return [prefix];

  return Object.entries(node).flatMap(([key, value]) =>
    keysOf(value, prefix ? `${prefix}.${key}` : key),
  );
}

describe('message files', () => {
  it('hold the same keys in French and in English', () => {
    expect(keysOf(en).sort()).toEqual(keysOf(fr).sort());
  });

  it('have no empty message', () => {
    for (const messages of [fr, en]) {
      for (const key of keysOf(messages)) {
        expect(translate(messages, key as never), key).not.toBe('');
      }
    }
  });
});

describe('translate', () => {
  it('finds a message by its dotted path', () => {
    expect(translate(getMessages('fr'), 'home.status.title')).toBe('État du système');
    expect(translate(getMessages('en'), 'home.status.title')).toBe('System status');
  });

  it('gives the path itself when the message is missing', () => {
    expect(translate(getMessages('fr'), 'home.nothing' as never)).toBe('home.nothing');
    expect(translate(getMessages('fr'), 'home' as never)).toBe('home');
  });
});
