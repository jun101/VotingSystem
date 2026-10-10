import { describe, expect, it } from 'vitest';
import { getMessages, translate, type MessageKey, type MessageParams } from '@/lib/i18n/messages';
import { countText, seatsText } from './ballotText';

const fr = (key: MessageKey, params?: MessageParams) => translate(getMessages('fr'), key, params);
const en = (key: MessageKey, params?: MessageParams) => translate(getMessages('en'), key, params);

describe('countText', () => {
  it('counts zero and one as singular in French', () => {
    expect(countText(0, 'fr', fr)).toBe('0 poste');
    expect(countText(1, 'fr', fr)).toBe('1 poste');
    expect(countText(2, 'fr', fr)).toBe('2 postes');
    expect(countText(12, 'fr', fr)).toBe('12 postes');
  });

  it('counts only one as singular in English', () => {
    expect(countText(0, 'en', en)).toBe('0 positions');
    expect(countText(1, 'en', en)).toBe('1 position');
    expect(countText(3, 'en', en)).toBe('3 positions');
  });
});

describe('seatsText', () => {
  it('says 1 siège and n sièges', () => {
    expect(seatsText(1, 'fr', fr)).toBe('1 siège');
    expect(seatsText(3, 'fr', fr)).toBe('3 sièges');
    expect(seatsText(1, 'en', en)).toBe('1 seat');
    expect(seatsText(2, 'en', en)).toBe('2 seats');
  });
});
