import { describe, expect, it } from 'vitest';
import en from './messages/en.json';
import fr from './messages/fr.json';
import { getMessages, translate, translateIfAny } from './messages';

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

describe('placeholders', () => {
  it('fills {name} from the parameters', () => {
    const messages = { hi: 'Bonjour, {name} ({count})' };

    expect(translateIfAny(messages, 'hi', { name: 'Marie', count: 2 })).toBe('Bonjour, Marie (2)');
  });

  it('leaves a placeholder that has no value', () => {
    expect(translateIfAny({ hi: 'Bonjour, {name}' }, 'hi', {})).toBe('Bonjour, {name}');
    expect(translateIfAny({ hi: 'Bonjour, {name}' }, 'hi')).toBe('Bonjour, {name}');
  });

  it('gives null for a key that does not exist or is not a text', () => {
    expect(translateIfAny({ a: { b: 'x' } }, 'a.c')).toBeNull();
    expect(translateIfAny({ a: { b: 'x' } }, 'a')).toBeNull();
    expect(translateIfAny({ a: { b: 'x' } }, 'a.b.c')).toBeNull();
  });
});
