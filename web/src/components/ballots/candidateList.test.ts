import { describe, expect, it } from 'vitest';
import type { Ballot } from '@/lib/api/ballots';
import type { Candidate } from '@/lib/api/candidates';
import {
  candidateTotal,
  checksOf,
  detachParty,
  mergeCandidates,
  partyCounts,
  placeCandidate,
  removeCandidate,
  withCandidates,
} from './candidateList';

function candidate(id: string, ballot: string, position: number, party: string | null = null) {
  return {
    id,
    ballot,
    party,
    first_name: id.toUpperCase(),
    last_name: 'X',
    sex: 'female',
    slogan: null,
    biography: null,
    photo: null,
    position,
    created_at: '',
    updated_at: '',
  } satisfies Candidate;
}

function ballot(id: string, position: number, candidates: Candidate[] = []): Ballot {
  return {
    id,
    title: `Poste ${id}`,
    description: null,
    position,
    seats: 1,
    allow_blank: true,
    candidates_count: candidates.length,
    candidates,
    created_at: '',
    updated_at: '',
  };
}

const ids = (b: Ballot) => b.candidates.map((c) => c.id).join('');
const positions = (b: Ballot) => b.candidates.map((c) => c.position);

const list = () => [
  ballot('p', 1, [candidate('a', 'p', 1), candidate('b', 'p', 2, 'ens'), candidate('c', 'p', 3)]),
  ballot('s', 2, [candidate('d', 's', 1, 'ens')]),
  ballot('t', 3),
];

describe('withCandidates', () => {
  it('replaces one ballot, renumbers and counts', () => {
    const [p, s] = withCandidates(list(), 'p', [candidate('c', 'p', 3), candidate('a', 'p', 1)]);

    expect(ids(p!)).toBe('ca');
    expect(positions(p!)).toEqual([1, 2]);
    expect(p!.candidates_count).toBe(2);
    expect(ids(s!)).toBe('d');
  });
});

describe('placeCandidate', () => {
  it('adds a new candidate at the end of its ballot', () => {
    const [, , t] = placeCandidate(list(), candidate('e', 't', 1));

    expect(ids(t!)).toBe('e');
    expect(t!.candidates_count).toBe(1);
  });

  it('changes a candidate where it stands', () => {
    const [p] = placeCandidate(list(), { ...candidate('b', 'p', 2), first_name: 'NEW' });

    expect(ids(p!)).toBe('abc');
    expect(p!.candidates[1]!.first_name).toBe('NEW');
  });

  it('moves a candidate to the end of the other ballot and closes the gap', () => {
    const [p, s] = placeCandidate(list(), candidate('a', 's', 2));

    expect(ids(p!)).toBe('bc');
    expect(positions(p!)).toEqual([1, 2]);
    expect(p!.candidates_count).toBe(2);
    expect(ids(s!)).toBe('da');
    expect(positions(s!)).toEqual([1, 2]);
  });
});

describe('removeCandidate', () => {
  it('takes it out and closes the gap', () => {
    const [p, s] = removeCandidate(list(), 'b');

    expect(ids(p!)).toBe('ac');
    expect(positions(p!)).toEqual([1, 2]);
    expect(p!.candidates_count).toBe(2);
    expect(ids(s!)).toBe('d');
  });
});

describe('detachParty', () => {
  it('makes the candidates of a deleted party independent, in every ballot', () => {
    const [p, s] = detachParty(list(), 'ens');

    expect(p!.candidates.map((c) => c.party)).toEqual([null, null, null]);
    expect(s!.candidates[0]!.party).toBeNull();
  });
});

describe('mergeCandidates', () => {
  it('takes the server candidates and keeps the order and fields of the ballots shown', () => {
    const shown = [list()[1]!, list()[0]!];
    const fresh = [
      ballot('p', 1, [candidate('z', 'p', 1)]),
      { ...ballot('s', 2, []), title: 'Changed elsewhere' },
    ];
    const [s, p] = mergeCandidates(shown, fresh);

    expect(s!.id).toBe('s');
    expect(s!.title).toBe('Poste s');
    expect(s!.candidates).toEqual([]);
    expect(s!.candidates_count).toBe(0);
    expect(ids(p!)).toBe('z');
  });

  it('leaves a ballot the server does not know', () => {
    const [p] = mergeCandidates([ballot('p', 1, [candidate('a', 'p', 1)])], []);

    expect(ids(p!)).toBe('a');
  });
});

describe('counts', () => {
  it('totals the candidates and counts them by party', () => {
    expect(candidateTotal(list())).toBe(4);
    expect(candidateTotal([])).toBe(0);
    expect(partyCounts(list()).get('ens')).toBe(2);
    expect(partyCounts(list()).get('other')).toBeUndefined();
  });
});

describe('checksOf', () => {
  it('lists the ballots with none or one candidate, with their card place', () => {
    const checks = checksOf(list());

    expect(checks.map((c) => [c.n, c.kind, c.ballot.id])).toEqual([
      [2, 'one', 's'],
      [3, 'none', 't'],
    ]);
  });

  it('lists nothing when every ballot has two candidates or more', () => {
    expect(checksOf([ballot('p', 1, [candidate('a', 'p', 1), candidate('b', 'p', 2)])])).toEqual(
      [],
    );
  });
});
