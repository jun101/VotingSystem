import { describe, expect, it } from 'vitest';
import type { CredentialLine, CredentialState, GeneratedFile } from '@/lib/api/credentials';
import {
  canGenerateAll,
  canGenerateGroup,
  codesLocked,
  downloadsOf,
  EMAIL_POLL_MS,
  isRunning,
  lineState,
  mergeFiles,
  missingIn,
  outcomeOf,
  percentOf,
  POLL_MS,
  pollDelay,
  runBodyOf,
} from './codeRules';

const G4 = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };

function line(over: Partial<CredentialLine> = {}): CredentialLine {
  return {
    group: G4,
    voters: 3,
    with_code: 0,
    with_email: 0,
    email_queued: 0,
    email_sent: 0,
    email_failed: 0,
    files_ready: 0,
    ...over,
  };
}

function state(over: Partial<CredentialState> = {}): CredentialState {
  return {
    voters: 6,
    with_code: 0,
    without_code: 6,
    with_email: 0,
    email_queued: 0,
    email_sent: 0,
    email_failed: 0,
    run_in_progress: false,
    groups: [],
    ...over,
  };
}

let n = 0;

function file(over: Partial<GeneratedFile> = {}): GeneratedFile {
  n += 1;

  return {
    id: `50000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
    kind: 'slips_pdf',
    group: G4,
    part: 1,
    parts: 1,
    voter_count: 3,
    status: 'ready',
    failure: null,
    size_bytes: 1000,
    expires_at: '2026-10-20T00:00:00Z',
    created_at: '2026-10-10T19:12:05Z',
    ...over,
  };
}

describe('codesLocked', () => {
  it.each(['draft', 'scheduled', 'open'] as const)('lets a %s election make codes', (status) => {
    expect(codesLocked(status)).toBe(false);
  });

  it.each(['closed', 'published', 'archived'] as const)('locks a %s election', (status) => {
    expect(codesLocked(status)).toBe(true);
  });
});

describe('buttons', () => {
  it('offers "everyone" only when someone lacks a code and nothing runs', () => {
    expect(canGenerateAll(state(), false)).toBe(true);
    expect(canGenerateAll(state(), true)).toBe(false);
    expect(canGenerateAll(state({ without_code: 0, with_code: 6 }), false)).toBe(false);
  });

  it('offers a group only while some voter lacks a code', () => {
    expect(canGenerateGroup(line(), false)).toBe(true);
    expect(canGenerateGroup(line({ with_code: 3 }), false)).toBe(false);
    expect(canGenerateGroup(line(), true)).toBe(false);
    expect(canGenerateGroup(line({ voters: 0 }), false)).toBe(false);
    expect(missingIn(line({ with_code: 1 }))).toBe(2);
  });

  it('runs a group, or the voters with no group', () => {
    expect(runBodyOf(line())).toEqual({ scope: 'group', group: G4.id });
    expect(runBodyOf(line({ group: null }))).toEqual({ scope: 'ungrouped' });
  });
});

describe('running', () => {
  it('is on while a file is queued, and not for messages that wait to be sent', () => {
    expect(isRunning([file()])).toBe(false);
    expect(isRunning([file({ status: 'queued' })])).toBe(true);
    expect(isRunning([])).toBe(false);
  });

  it('reads the state fast for files, slowly for messages, and not at all otherwise', () => {
    expect(pollDelay(state(), [file({ status: 'queued' })])).toBe(POLL_MS);
    expect(pollDelay(state({ email_queued: 3 }), [file()])).toBe(EMAIL_POLL_MS);
    expect(pollDelay(state({ email_queued: 3 }), [file({ status: 'queued' })])).toBe(POLL_MS);
    expect(pollDelay(state({ run_in_progress: true }), [file()])).toBeNull();
    expect(EMAIL_POLL_MS).toBeGreaterThan(POLL_MS);
  });

  it('keeps the generate buttons usable while only messages wait', () => {
    expect(canGenerateAll(state({ without_code: 2, email_queued: 5 }), isRunning([file()]))).toBe(
      true,
    );
  });
});

describe('progress', () => {
  it('gives whole percentages', () => {
    expect(percentOf(0, 0)).toBe(0);
    expect(percentOf(1, 3)).toBe(33);
    expect(percentOf(3, 3)).toBe(100);
  });

  it('names the state of a card', () => {
    expect(lineState(line({ voters: 0 }))).toBe('empty');
    expect(lineState(line())).toBe('none');
    expect(lineState(line({ with_code: 1 }))).toBe('partial');
    expect(lineState(line({ with_code: 3 }))).toBe('done');
  });
});

describe('downloadsOf', () => {
  it('gives nothing without a ready file of the group', () => {
    expect(downloadsOf([], line())).toEqual([]);
    expect(downloadsOf([file({ status: 'queued' }), file({ status: 'failed' })], line())).toEqual(
      [],
    );
    expect(downloadsOf([file({ group: null })], line())).toEqual([]);
  });

  it('gives the group file, and the file of the voters with no group for the null line', () => {
    const one = file();
    const none = file({ group: null });

    expect(downloadsOf([one, none], line())).toEqual([one]);
    expect(downloadsOf([one, none], line({ group: null }))).toEqual([none]);
  });

  it('gives every ready file, newest first, then in order of part', () => {
    const p2 = file({ part: 2, parts: 2, voter_count: 1000 });
    const p1 = file({ part: 1, parts: 2, voter_count: 1000 });
    const old = file({ created_at: '2026-10-09T10:00:00Z' });

    expect(downloadsOf([p2, old, p1], line())).toEqual([p1, p2, old]);
  });

  it('keeps a reissued one-slip file, newest first, and does not group by equal timestamps', () => {
    const group = file({ created_at: '2026-10-10T19:12:05Z' });
    const slip = file({ voter_count: 1, created_at: '2026-10-10T20:00:00Z' });
    const sameTime = file({ part: 1, parts: 1, created_at: '2026-10-10T19:12:05Z' });

    expect(downloadsOf([group, slip], line())).toEqual([slip, group]);
    expect(downloadsOf([slip], line())).toEqual([slip]);
    expect(downloadsOf([group, sameTime], line())).toHaveLength(2);
  });
});

describe('outcomeOf', () => {
  it('waits while a watched file is queued, and counts ready voters and failures after', () => {
    const a = file({ voter_count: 3 });
    const b = file({ voter_count: 2, status: 'queued' });
    const watched = new Set([a.id, b.id]);

    expect(outcomeOf([a, b], watched)).toBeNull();
    expect(outcomeOf([a, { ...b, status: 'ready' }], watched)).toEqual({ voters: 5, failed: 0 });
    expect(
      outcomeOf([a, { ...b, status: 'failed', failure: 'generation_failed' }], watched),
    ).toEqual({
      voters: 3,
      failed: 1,
    });
    expect(outcomeOf([a], new Set())).toBeNull();
  });
});

describe('outcomeOf with a file of codes', () => {
  it('counts the voters of the slips only, so the file of codes is not counted twice', () => {
    const slips = file({ voter_count: 3 });
    const codes = file({ kind: 'codes_xlsx', group: null, voter_count: 3 });
    const watched = new Set([slips.id, codes.id]);

    expect(outcomeOf([slips, codes], watched)).toEqual({ voters: 3, failed: 0 });
  });

  it('reports a failed file of codes on its own, not as a failed slip file', () => {
    const slips = file({ voter_count: 3 });
    const codes = file({ kind: 'codes_csv', group: null, status: 'failed' });
    const watched = new Set([slips.id, codes.id]);

    expect(outcomeOf([slips, codes], watched)).toEqual({
      voters: 3,
      failed: 0,
      codeFileFailed: true,
    });
  });
});

describe('mergeFiles', () => {
  it('puts the fresh files first and replaces the known ones', () => {
    const a = file();
    const b = file();
    const fresh = { ...a, status: 'queued' as const };

    expect(mergeFiles([a, b], [fresh])).toEqual([fresh, b]);
  });
});
