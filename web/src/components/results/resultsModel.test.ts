import { describe, expect, it } from 'vitest';
import type { ResultBallot } from '@/lib/api/results';
import {
  NOTE_MAX,
  barShare,
  canCreateSecondRound,
  candidateState,
  noteChanged,
  noteLength,
  noteTooLong,
  tiedNames,
} from './resultsModel';

function ballot(over: Partial<ResultBallot> = {}): ResultBallot {
  return {
    id: 'b',
    title: 'Président(e)',
    seats: 1,
    scope: 'general',
    groups: [],
    eligible: 6,
    voted: 6,
    cast: 6,
    blank: 0,
    candidates: [
      { id: 'c1', first_name: 'Ana', last_name: 'Désir', party: null, votes: 3, percentage: 50 },
      { id: 'c2', first_name: 'Luc', last_name: 'Pierre', party: null, votes: 3, percentage: 50 },
    ],
    winners: [],
    tie: { candidates: ['c1', 'c2'], seats_left: 1 },
    check: { recorded: 6, expected: 6, ok: true },
    note: null,
    second_round: null,
    ...over,
  };
}

describe('barShare', () => {
  it('is the share of the votes cast', () => {
    expect(barShare(3, 6)).toBe(0.5);
    expect(barShare(6, 6)).toBe(1);
  });

  it('is 0 with no vote or nothing cast, and never above 1', () => {
    expect(barShare(0, 6)).toBe(0);
    expect(barShare(2, 0)).toBe(0);
    expect(barShare(9, 6)).toBe(1);
  });
});

describe('note counter', () => {
  it('counts characters, not code units', () => {
    expect(noteLength('Égalité')).toBe(7);
    expect(noteLength('a😀')).toBe(2);
  });

  it('flags a note over the limit', () => {
    expect(noteTooLong('a'.repeat(NOTE_MAX))).toBe(false);
    expect(noteTooLong('a'.repeat(NOTE_MAX + 1))).toBe(true);
  });

  it('compares the trimmed draft with the saved note', () => {
    expect(noteChanged('', null)).toBe(false);
    expect(noteChanged('  ', null)).toBe(false);
    expect(noteChanged('Hi ', 'Hi')).toBe(false);
    expect(noteChanged('Hello', 'Hi')).toBe(true);
    expect(noteChanged('', 'Hi')).toBe(true);
  });
});

describe('ties', () => {
  it('names the tied candidates and marks them', () => {
    expect(tiedNames(ballot())).toEqual(['Ana Désir', 'Luc Pierre']);
    expect(candidateState(ballot(), 'c1')).toBe('tied');
    expect(candidateState(ballot({ winners: ['c1'], tie: null }), 'c1')).toBe('winner');
  });

  it('offers a second round only for a tie with none created', () => {
    expect(canCreateSecondRound(ballot())).toBe(true);
    expect(canCreateSecondRound(ballot({ second_round: 'x' }))).toBe(false);
    expect(canCreateSecondRound(ballot({ tie: null }))).toBe(false);
  });
});
