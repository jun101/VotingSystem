import { describe, expect, it } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import {
  EMPTY_COPY,
  copyBody,
  copyCount,
  copyErrorKey,
  copyReady,
  renamesOf,
  toggleCopyGroup,
  type CopyDraft,
  type CopySource,
} from './copyRules';

const SOURCE: CopySource = {
  groups: [
    { id: 'g-4', name: '4e année', voters_count: 12 },
    { id: 'g-5', name: '5e année', voters_count: 8 },
  ],
  ungrouped: 3,
};

const some = (patch: Partial<CopyDraft>): CopyDraft => ({
  ...EMPTY_COPY,
  source: 'e-1',
  mode: 'some',
  ...patch,
});

describe('copyBody', () => {
  it('sends the source alone for every voter', () => {
    expect(copyBody({ ...EMPTY_COPY, source: 'e-1', groups: ['g-4'], ungrouped: true })).toEqual({
      source: 'e-1',
    });
  });

  it('sends the groups and no ungrouped voter by default', () => {
    expect(copyBody(some({ groups: ['g-4'] }))).toEqual({
      source: 'e-1',
      groups: ['g-4'],
      include_ungrouped: false,
    });
  });

  it('sends the ungrouped voters alone as an empty list', () => {
    expect(copyBody(some({ ungrouped: true }))).toEqual({
      source: 'e-1',
      groups: [],
      include_ungrouped: true,
    });
  });

  it('sends the trimmed renames of the ticked groups only', () => {
    const body = copyBody(
      some({ groups: ['g-4'], rename: { 'g-4': '  5e année ', 'g-5': 'Autre' } }),
    );

    expect(body.rename).toEqual({ 'g-4': '5e année' });
  });

  it('leaves the rename out when every name is blank', () => {
    expect(copyBody(some({ groups: ['g-4'], rename: { 'g-4': '   ' } }))).not.toHaveProperty(
      'rename',
    );
  });
});

describe('copyBody with every group', () => {
  it('sends the trimmed renames with the source alone', () => {
    const body = copyBody({
      ...EMPTY_COPY,
      source: 'e-1',
      rename: { 'g-4': ' 5e année ', 'g-5': '  ' },
    });

    expect(body).toEqual({ source: 'e-1', rename: { 'g-4': '5e année' } });
  });
});

describe('renamesOf', () => {
  it('keeps nothing for an unticked group', () => {
    expect(renamesOf(some({ groups: [], rename: { 'g-4': 'X' } }))).toEqual({});
  });
});

describe('toggleCopyGroup', () => {
  it('adds and removes', () => {
    expect(toggleCopyGroup([], 'g-4')).toEqual(['g-4']);
    expect(toggleCopyGroup(['g-4', 'g-5'], 'g-4')).toEqual(['g-5']);
  });
});

describe('copyCount', () => {
  it('counts every voter, the ungrouped ones included', () => {
    expect(copyCount({ ...EMPTY_COPY, source: 'e-1' }, SOURCE)).toBe(23);
  });

  it('counts the ticked groups', () => {
    expect(copyCount(some({ groups: ['g-5'] }), SOURCE)).toBe(8);
  });

  it('adds the ungrouped voters when asked', () => {
    expect(copyCount(some({ groups: ['g-5'], ungrouped: true }), SOURCE)).toBe(11);
    expect(copyCount(some({}), SOURCE)).toBe(0);
  });
});

describe('copyReady', () => {
  it('needs a source', () => {
    expect(copyReady(EMPTY_COPY)).toBe(false);
    expect(copyReady({ ...EMPTY_COPY, source: 'e-1' })).toBe(true);
  });

  it('needs a group or the ungrouped voters in some mode', () => {
    expect(copyReady(some({}))).toBe(false);
    expect(copyReady(some({ groups: ['g-4'] }))).toBe(true);
    expect(copyReady(some({ ungrouped: true }))).toBe(true);
  });
});

describe('copyErrorKey', () => {
  it('names the rule of the field a 422 gives', () => {
    expect(copyErrorKey(new ApiError(422, 'validation_failed', { source: ['same'] }))).toBe(
      'validation.copy.source.same',
    );
    expect(copyErrorKey(new ApiError(422, 'validation_failed', { rename: ['invalid'] }))).toBe(
      'validation.copy.rename.invalid',
    );
  });

  it('uses the code of the other answers', () => {
    expect(copyErrorKey(new ApiError(409, 'voter_limit_reached'))).toBe(
      'errors.voter_limit_reached',
    );
    expect(copyErrorKey(new ApiError(409, 'group_limit_reached'))).toBe(
      'errors.group_limit_reached',
    );
  });
});
