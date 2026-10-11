import { describe, expect, it } from 'vitest';
import type { PublicBallot } from '@/lib/api/public';
import {
  absoluteUrl,
  barOf,
  electedNames,
  initials,
  originOf,
  shareLinks,
  tiedCandidates,
  winnerNames,
} from './share';

const headers = (values: Record<string, string>) => ({
  get: (name: string) => values[name.toLowerCase()] ?? null,
});

const ballot = (over: Partial<PublicBallot> = {}): PublicBallot => ({
  id: 'b1',
  title: 'Président(e)',
  seats: 1,
  groups: [],
  candidates: [
    { id: 'c1', first_name: 'Ada', last_name: 'Lovelace', party: null, photo: null },
    { id: 'c2', first_name: 'Bob', last_name: 'Martin', party: null, photo: null },
  ],
  winners: ['c1'],
  tie: null,
  note: null,
  ...over,
});

describe('originOf', () => {
  it('prefers the forwarded host and protocol', () => {
    expect(
      originOf(
        headers({
          'x-forwarded-host': 'vote.example',
          'x-forwarded-proto': 'https',
          host: 'web:3000',
        }),
      ),
    ).toBe('https://vote.example');
  });

  it('takes the first value of a chain', () => {
    expect(
      originOf(
        headers({ 'x-forwarded-host': 'a.example, b.example', 'x-forwarded-proto': 'https, http' }),
      ),
    ).toBe('https://a.example');
  });

  it('uses http for a host on this machine and https for another', () => {
    expect(originOf(headers({ host: 'localhost:8080' }))).toBe('http://localhost:8080');
    expect(originOf(headers({ host: 'vote.example' }))).toBe('https://vote.example');
  });
});

describe('absoluteUrl', () => {
  it('joins an origin and a path once', () => {
    expect(absoluteUrl('https://vote.example/', '/results/abc')).toBe(
      'https://vote.example/results/abc',
    );
    expect(absoluteUrl('https://vote.example', 'results/abc')).toBe(
      'https://vote.example/results/abc',
    );
  });
});

describe('shareLinks', () => {
  const links = shareLinks('Conseil 2026 – Collège', 'https://vote.example/results/abc');

  it('puts the title and the address in the WhatsApp text', () => {
    expect(links.whatsapp).toBe(
      `https://wa.me/?text=${encodeURIComponent('Conseil 2026 – Collège https://vote.example/results/abc')}`,
    );
  });

  it('gives Facebook the encoded address', () => {
    expect(links.facebook).toBe(
      'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fvote.example%2Fresults%2Fabc',
    );
  });

  it('gives X the text and the address apart', () => {
    expect(links.x).toContain('https://x.com/intent/post?text=');
    expect(links.x).toContain('&url=https%3A%2F%2Fvote.example%2Fresults%2Fabc');
  });
});

describe('winners and ties', () => {
  it('lists the names of the winners in the order of the candidates', () => {
    expect(winnerNames(ballot({ winners: ['c2', 'c1'] }))).toEqual(['Ada Lovelace', 'Bob Martin']);
  });

  it('lists the tied candidates', () => {
    expect(
      tiedCandidates(ballot({ winners: [], tie: { candidates: ['c1', 'c2'], seats_left: 1 } })),
    ).toEqual(['Ada Lovelace', 'Bob Martin']);
  });

  it('sums up the elected people of all ballots once each', () => {
    expect(electedNames([ballot(), ballot({ id: 'b2' })])).toEqual(['Ada Lovelace']);
    expect(electedNames([ballot({ winners: [] })])).toEqual([]);
  });
});

describe('barOf and initials', () => {
  it('turns a percentage into a share between 0 and 1', () => {
    expect(barOf(52.3)).toBeCloseTo(0.523);
    expect(barOf(undefined)).toBe(0);
    expect(barOf(140)).toBe(1);
  });

  it('writes two letters', () => {
    expect(initials('Collège Étoile du Matin')).toBe('CM');
    expect(initials('Ada')).toBe('AD');
  });
});
