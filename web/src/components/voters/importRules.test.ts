import { describe, expect, it } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import { fileLabel, hasVoterFileExtension, importAllowed, uploadErrorKey } from './importRules';

describe('importAllowed', () => {
  it.each([
    ['draft', true],
    ['scheduled', true],
    ['open', false],
    ['closed', false],
    ['published', false],
    ['archived', false],
  ] as const)('%s is %s', (status, allowed) => {
    expect(importAllowed(status)).toBe(allowed);
  });
});

describe('uploadErrorKey', () => {
  it.each(['required', 'invalid_type', 'empty', 'too_large'])(
    'names the rule %s of the file',
    (code) => {
      const error = new ApiError(422, 'validation_failed', { file: [code] });

      expect(uploadErrorKey(error)).toBe(`voterImport.error.file.${code}`);
    },
  );

  it('gives no key to an unknown rule or to another field', () => {
    expect(uploadErrorKey(new ApiError(422, 'validation_failed', { file: ['weird'] }))).toBeNull();
    expect(
      uploadErrorKey(new ApiError(422, 'validation_failed', { other: ['required'] })),
    ).toBeNull();
  });

  it('names the two conflicts', () => {
    expect(uploadErrorKey(new ApiError(409, 'import_in_progress'))).toBe(
      'errors.import_in_progress',
    );
    expect(uploadErrorKey(new ApiError(409, 'election_voters_locked'))).toBe(
      'errors.election_voters_locked',
    );
  });

  it('leaves the other errors to the generic text', () => {
    expect(uploadErrorKey(new ApiError(0, 'network'))).toBeNull();
    expect(uploadErrorKey(new ApiError(429, 'too_many_attempts'))).toBeNull();
  });
});

describe('file names', () => {
  it('keeps the name alone', () => {
    expect(fileLabel('C:\\fakepath\\liste.xlsx')).toBe('liste.xlsx');
    expect(fileLabel('liste élèves.csv')).toBe('liste élèves.csv');
  });

  it('knows the usual endings, in any case', () => {
    expect(hasVoterFileExtension('liste.XLSX')).toBe(true);
    expect(hasVoterFileExtension('liste.csv')).toBe(true);
    expect(hasVoterFileExtension('liste.png')).toBe(false);
    expect(hasVoterFileExtension('liste')).toBe(false);
  });
});
