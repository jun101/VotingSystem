import { describe, expect, it } from 'vitest';
import { getMessages, translate } from '@/lib/i18n/messages';
import type { Locale } from '@/lib/i18n/locale';
import { candidatesText, partiesText } from './partyText';

function tIn(locale: Locale) {
  const messages = getMessages(locale);

  return (key: Parameters<typeof translate>[1], params?: Parameters<typeof translate>[2]) =>
    translate(messages, key, params);
}

describe('partiesText', () => {
  it('counts zero as one in French', () => {
    const t = tIn('fr');

    expect(partiesText(0, 'fr', t)).toBe('0 parti');
    expect(partiesText(1, 'fr', t)).toBe('1 parti');
    expect(partiesText(2, 'fr', t)).toBe('2 partis');
  });

  it('counts zero as many in English', () => {
    const t = tIn('en');

    expect(partiesText(0, 'en', t)).toBe('0 parties');
    expect(partiesText(1, 'en', t)).toBe('1 party');
  });
});

describe('candidatesText', () => {
  it('says there is none, then counts', () => {
    const t = tIn('fr');

    expect(candidatesText(0, 'fr', t)).toBe('Aucun candidat');
    expect(candidatesText(1, 'fr', t)).toBe('1 candidat');
    expect(candidatesText(4, 'fr', t)).toBe('4 candidats');
    expect(candidatesText(0, 'en', tIn('en'))).toBe('No candidate');
  });
});
