import { describe, expect, it } from 'vitest';
import {
  EMPTY_DRAFT,
  apiFieldErrors,
  bodyOf,
  draftOf,
  nextDraft,
  validateVoter,
  type VoterDraft,
} from './voterForm';

const draft = (changes: Partial<VoterDraft>): VoterDraft => ({
  ...EMPTY_DRAFT,
  full_name: 'Rose-Marie Désir',
  ...changes,
});

describe('validateVoter', () => {
  it('accepts a name alone', () => {
    expect(validateVoter(draft({}))).toEqual({});
  });

  it('wants a name that is not only spaces, at most 150 characters', () => {
    expect(validateVoter(draft({ full_name: '   ' })).full_name).toBe('required');
    expect(validateVoter(draft({ full_name: 'a'.repeat(150) })).full_name).toBeUndefined();
    expect(validateVoter(draft({ full_name: 'a'.repeat(151) })).full_name).toBe('max');
  });

  it('limits the group, the identifier and the email', () => {
    expect(validateVoter(draft({ group: 'a'.repeat(101) })).group).toBe('max');
    expect(validateVoter(draft({ identifier: 'a'.repeat(51) })).identifier).toBe('max');
    expect(validateVoter(draft({ email: 'a'.repeat(256) })).email).toBe('max');
  });

  it('accepts phone numbers with digits, spaces and + - ( ) . only', () => {
    expect(validateVoter(draft({ phone: '+509 3712-4455 (12).' })).phone).toBeUndefined();
    expect(validateVoter(draft({ phone: '3712 abc' })).phone).toBe('invalid');
    expect(validateVoter(draft({ phone: '1'.repeat(31) })).phone).toBe('max');
  });
});

describe('bodyOf', () => {
  it('trims, and sends a blank optional field as null', () => {
    expect(
      bodyOf(
        draft({ full_name: '  Rose ', group: '  ', identifier: ' E-1 ', email: '', phone: ' ' }),
      ),
    ).toEqual({ full_name: 'Rose', group: null, identifier: 'E-1', email: null, phone: null });
  });
});

describe('draftOf and nextDraft', () => {
  it('fills the form from a voter, a missing value as empty text', () => {
    expect(
      draftOf({
        full_name: 'Avant',
        group: { id: 'g', name: '4e année' },
        identifier: null,
        email: 'a@example.ht',
        phone: null,
      }),
    ).toEqual({
      full_name: 'Avant',
      group: '4e année',
      identifier: '',
      email: 'a@example.ht',
      phone: '',
    });
  });

  it('keeps the group after "save and add another" and clears the rest', () => {
    expect(
      nextDraft({
        full_name: 'Un',
        group: '5e année',
        identifier: 'E-1',
        email: 'a@example.ht',
        phone: '1',
      }),
    ).toEqual({ ...EMPTY_DRAFT, group: '5e année' });
  });
});

describe('apiFieldErrors', () => {
  it('keeps the first code of each field of the form and nothing else', () => {
    expect(
      apiFieldErrors({ identifier: ['unique', 'max'], email: ['email'], election: ['x'] }),
    ).toEqual({ identifier: 'unique', email: 'email' });
  });
});
