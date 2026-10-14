import { describe, expect, it } from 'vitest';
import { getMessages, translate, type MessageKey, type MessageParams } from '@/lib/i18n/messages';
import {
  initialsOf,
  pageCount,
  pageItems,
  rangeOf,
  rangeText,
  suggestGroups,
  toneOf,
  votersText,
} from './voterText';

const fr = (key: MessageKey, params?: MessageParams) => translate(getMessages('fr'), key, params);
const en = (key: MessageKey, params?: MessageParams) => translate(getMessages('en'), key, params);

describe('pageCount and rangeOf', () => {
  it('counts 24 voters a page, at least one page', () => {
    expect(pageCount(0)).toBe(1);
    expect(pageCount(24)).toBe(1);
    expect(pageCount(25)).toBe(2);
    expect(pageCount(312)).toBe(13);
  });

  it('gives the first and last place of a page', () => {
    expect(rangeOf(1, 312)).toEqual({ from: 1, to: 24 });
    expect(rangeOf(2, 26)).toEqual({ from: 25, to: 26 });
    expect(rangeOf(1, 0)).toEqual({ from: 0, to: 0 });
  });
});

describe('rangeText', () => {
  it('reads "1 à 24 sur 312 électeurs"', () => {
    expect(rangeText(1, 312, 'fr', fr)).toBe('1 à 24 sur 312 électeurs');
    expect(rangeText(13, 312, 'fr', fr)).toBe('289 à 312 sur 312 électeurs');
  });

  it('says one voter in the singular and reads in English', () => {
    expect(rangeText(1, 1, 'fr', fr)).toBe('1 sur 1 électeur');
    expect(rangeText(1, 26, 'en', en)).toBe('1 to 24 of 26 voters');
  });
});

describe('votersText', () => {
  it('counts zero as singular in French and as plural in English', () => {
    expect(votersText(0, 'fr', fr)).toBe('0 électeur');
    expect(votersText(3, 'fr', fr)).toBe('3 électeurs');
    expect(votersText(0, 'en', en)).toBe('0 voters');
  });
});

describe('pageItems', () => {
  it('shows every page when there are few', () => {
    expect(pageItems(1, 1)).toEqual([1]);
    expect(pageItems(2, 4)).toEqual([1, 2, 3, 4]);
  });

  it('leaves gaps around the current page', () => {
    expect(pageItems(1, 13)).toEqual([1, 2, 'gap', 13]);
    expect(pageItems(7, 13)).toEqual([1, 'gap', 6, 7, 8, 'gap', 13]);
    expect(pageItems(13, 13)).toEqual([1, 'gap', 12, 13]);
  });

  it('shows a single missing number instead of a gap', () => {
    expect(pageItems(4, 6)).toEqual([1, 2, 3, 4, 5, 6]);
  });
});

describe('initialsOf', () => {
  it('takes the first letters of the first and the last word', () => {
    expect(initialsOf('Rose-Marie Désir')).toBe('RD');
    expect(initialsOf('Jean Pierre Louis')).toBe('JL');
    expect(initialsOf('émile')).toBe('ÉM');
    expect(initialsOf('   ')).toBe('?');
  });
});

describe('toneOf', () => {
  it('is stable and within range', () => {
    expect(toneOf('Rose', 4)).toBe(toneOf('Rose', 4));

    for (const name of ['A', 'Rose-Marie Désir', 'Nadège Louis', '']) {
      expect(toneOf(name, 4)).toBeGreaterThanOrEqual(0);
      expect(toneOf(name, 4)).toBeLessThan(4);
    }
  });
});

describe('suggestGroups', () => {
  const groups = [
    { name: '4e année' },
    { name: '5e année' },
    { name: 'Terminale A' },
    { name: 'Terminale B' },
  ];

  it('offers every group when the field is empty', () => {
    expect(suggestGroups(groups, '')).toEqual([
      '4e année',
      '5e année',
      'Terminale A',
      'Terminale B',
    ]);
  });

  it('keeps the names that contain the text, ignoring case and accents', () => {
    expect(suggestGroups(groups, 'ANNEE')).toEqual(['4e année', '5e année']);
    expect(suggestGroups(groups, 'termin')).toEqual(['Terminale A', 'Terminale B']);
  });

  it('puts the names that start with the text first', () => {
    expect(
      suggestGroups([{ name: 'Section A' }, { name: 'A' }, { name: 'Alpha' }], 'a').sort(),
    ).toEqual(['A', 'Alpha', 'Section A'].filter((name) => name !== 'A'));
    expect(suggestGroups([{ name: 'Section A' }, { name: 'Alpha' }], 'a')[0]).toBe('Alpha');
  });

  it('does not offer the name that is already typed, and has a limit', () => {
    expect(suggestGroups(groups, '  terminale a ')).toEqual([]);
    expect(
      suggestGroups(
        Array.from({ length: 20 }, (_, index) => ({ name: `Groupe ${index}` })),
        'groupe',
      ),
    ).toHaveLength(8);
  });
});
