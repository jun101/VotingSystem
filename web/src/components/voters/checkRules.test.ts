import { describe, expect, it } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import {
  actionErrorKey,
  applyChange,
  clampPage,
  columnOfValue,
  failureKey,
  mappingChange,
  previewPages,
  previewRange,
  progressCounts,
  progressPercent,
  reasonKey,
  rowStatusKeys,
  shouldPoll,
  stepOf,
  takenColumns,
  validateMapping,
  valueOfColumn,
} from './checkRules';

describe('reason words', () => {
  it('maps a known reason code to its message key', () => {
    expect(reasonKey('email_invalid')).toBe('voterImport.check.reason.email_invalid');
    expect(reasonKey('identifier_exists')).toBe('voterImport.check.reason.identifier_exists');
  });

  it('never gives a raw code: an unknown reason gets the generic words', () => {
    expect(reasonKey('something_new')).toBe('voterImport.check.reason.unknown');
  });

  it('says Prêt for a valid row and one key per reason otherwise', () => {
    expect(rowStatusKeys({ status: 'ok', reasons: [] })).toEqual(['voterImport.check.status.ok']);
    expect(
      rowStatusKeys({ status: 'invalid', reasons: ['full_name_required', 'email_invalid'] }),
    ).toEqual([
      'voterImport.check.reason.full_name_required',
      'voterImport.check.reason.email_invalid',
    ]);
    expect(
      rowStatusKeys({
        status: 'duplicate',
        reasons: ['duplicate_email_in_file', 'duplicate_email_in_file'],
      }),
    ).toEqual(['voterImport.check.reason.duplicate_email_in_file']);
  });

  it('falls back to words when a row has no reason', () => {
    expect(rowStatusKeys({ status: 'duplicate', reasons: [] })).toEqual([
      'voterImport.check.status.duplicate',
    ]);
    expect(rowStatusKeys({ status: 'invalid', reasons: [] })).toEqual([
      'voterImport.check.reason.unknown',
    ]);
  });

  it('says a failure in words', () => {
    expect(failureKey('no_header')).toBe('voterImport.check.failure.no_header');
    expect(failureKey('import_failed')).toBe('voterImport.check.failure.import_failed');
    expect(failureKey('weird')).toBe('voterImport.check.failure.unknown');
    expect(failureKey(null)).toBe('voterImport.check.failure.unknown');
  });

  it('says a refused action in words', () => {
    expect(actionErrorKey(new ApiError(409, 'nothing_to_import'))).toBe('errors.nothing_to_import');
    expect(actionErrorKey(new ApiError(409, 'voter_limit_reached'))).toBe(
      'errors.voter_limit_reached',
    );
    expect(actionErrorKey(new ApiError(500, 'server_error'))).toBeNull();
  });
});

describe('mapping', () => {
  const full = { full_name: 0, group: 1, identifier: 2, email: 3, phone: null };

  it('accepts a mapping with a name and distinct columns in range', () => {
    expect(validateMapping(full, 4)).toBeNull();
  });

  it('refuses a missing name, a column out of range and a column used twice', () => {
    expect(validateMapping({ ...full, full_name: null }, 4)).toBe('full_name_required');
    expect(validateMapping({ ...full, phone: 4 }, 4)).toBe('out_of_range');
    expect(validateMapping({ ...full, phone: -1 }, 4)).toBe('out_of_range');
    expect(validateMapping({ ...full, phone: 0 }, 4)).toBe('column_used_twice');
  });

  it('reads a select value as a column or as "do not import"', () => {
    expect(columnOfValue('')).toBeNull();
    expect(columnOfValue('2')).toBe(2);
    expect(columnOfValue('x')).toBeNull();
    expect(valueOfColumn(null)).toBe('');
    expect(valueOfColumn(0)).toBe('0');
  });

  it('sends nothing when the column does not change', () => {
    expect(mappingChange(full, 'group', 1)).toBeNull();
    expect(mappingChange(full, 'phone', null)).toBeNull();
    expect(mappingChange(full, 'phone', 4)).toEqual({ phone: 4 });
    expect(mappingChange(full, 'email', null)).toEqual({ email: null });
    expect(mappingChange(null, 'group', null)).toBeNull();
  });

  it('applies a change and lists the columns the other fields take', () => {
    expect(applyChange(full, { phone: 4 })).toEqual({ ...full, phone: 4 });
    expect([...takenColumns(full, 'group')].sort()).toEqual([0, 2, 3]);
    expect(takenColumns(null, 'group').size).toBe(0);
  });
});

describe('progress', () => {
  it('turns the rows handled into a percent between 0 and 100', () => {
    expect(progressPercent(120, 300)).toBe(40);
    expect(progressPercent(0, 300)).toBe(0);
    expect(progressPercent(300, 300)).toBe(100);
    expect(progressPercent(500, 300)).toBe(100);
    expect(progressPercent(5, 0)).toBe(0);
    expect(progressPercent(-3, 10)).toBe(0);
  });

  it('keeps the counts coherent for the text "120 sur 300"', () => {
    expect(progressCounts(120, 300)).toEqual({ done: 120, total: 300 });
    expect(progressCounts(400, 300)).toEqual({ done: 300, total: 300 });
    expect(progressCounts(-1, 300)).toEqual({ done: 0, total: 300 });
  });
});

describe('polling and steps', () => {
  it('polls while the file is checked or imported, and only then', () => {
    expect(shouldPoll('uploaded')).toBe(true);
    expect(shouldPoll('importing')).toBe(true);
    expect(shouldPoll('checked')).toBe(false);
    expect(shouldPoll('done')).toBe(false);
    expect(shouldPoll('failed')).toBe(false);
  });

  it('puts the check on step 3 and the import and its end on step 4', () => {
    expect(stepOf('uploaded')).toBe(3);
    expect(stepOf('checked')).toBe(3);
    expect(stepOf('failed')).toBe(3);
    expect(stepOf('importing')).toBe(4);
    expect(stepOf('done')).toBe(4);
  });
});

describe('pages of the preview', () => {
  it('holds 50 rows a page', () => {
    expect(previewPages(0)).toBe(1);
    expect(previewPages(50)).toBe(1);
    expect(previewPages(51)).toBe(2);
    expect(previewPages(312)).toBe(7);
  });

  it('gives the first and last row of a page', () => {
    expect(previewRange(1, 312)).toEqual({ from: 1, to: 50 });
    expect(previewRange(7, 312)).toEqual({ from: 301, to: 312 });
    expect(previewRange(1, 0)).toEqual({ from: 0, to: 0 });
  });

  it('keeps the page between the first and the last', () => {
    expect(clampPage(0, 312)).toBe(1);
    expect(clampPage(9, 312)).toBe(7);
    expect(clampPage(3, 10)).toBe(1);
  });
});
