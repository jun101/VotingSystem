import { describe, expect, it } from 'vitest';
import { getMessages, translate } from '@/lib/i18n/messages';
import type { Locale } from '@/lib/i18n/locale';
import { candidateCountText, counterText } from './candidateText';

function tIn(locale: Locale) {
  const messages = getMessages(locale);

  return (key: Parameters<typeof translate>[1], params?: Parameters<typeof translate>[2]) =>
    translate(messages, key, params);
}

describe('candidateCountText', () => {
  it('counts zero as one in French', () => {
    const t = tIn('fr');

    expect(candidateCountText(0, 'fr', t)).toBe('0 candidat');
    expect(candidateCountText(1, 'fr', t)).toBe('1 candidat');
    expect(candidateCountText(2, 'fr', t)).toBe('2 candidats');
  });

  it('counts zero as many in English', () => {
    const t = tIn('en');

    expect(candidateCountText(0, 'en', t)).toBe('0 candidates');
    expect(candidateCountText(1, 'en', t)).toBe('1 candidate');
  });
});

describe('counterText', () => {
  it('writes the count and the maximum in both languages', () => {
    expect(counterText(19, 80, tIn('fr'))).toBe('19 sur 80');
    expect(counterText(19, 80, tIn('en'))).toBe('19 of 80');
  });
});
