/*
 * The pure parts of the check page (docs/slices/08-import.md, 08d). The server decides every
 * status and every count; this only chooses which words and which controls the page shows.
 */

import {
  IMPORT_ROWS_PER_PAGE,
  MAPPING_FIELDS,
  type ImportMapping,
  type ImportRow,
  type MappingField,
  type VoterImportStatus,
} from '@/lib/api/voterImports';
import type { ApiError } from '@/lib/api/errors';

/** How often a running import is read again. */
export const POLL_MS = 2000;

/** Whether the page must keep asking the API: the file is being checked or imported. */
export function shouldPoll(status: VoterImportStatus): boolean {
  return status === 'uploaded' || status === 'importing';
}

/** The step of the stepper (1 to 4) a status belongs to: the check is 3, the end is 4. */
export function stepOf(status: VoterImportStatus): 3 | 4 {
  return status === 'importing' || status === 'done' ? 4 : 3;
}

/** The share of rows handled, a whole number from 0 to 100. */
export function progressPercent(processed: number, total: number): number {
  if (!Number.isFinite(processed) || !Number.isFinite(total) || total <= 0) return 0;

  return Math.min(100, Math.max(0, Math.round((processed / total) * 100)));
}

/** The rows handled out of all of them, as the person reads it: `120 sur 300`. */
export function progressCounts(processed: number, total: number): { done: number; total: number } {
  const all = Math.max(0, total);

  return { done: Math.min(Math.max(0, processed), all), total: all };
}

/** The number of pages of the preview for this many rows. */
export function previewPages(total: number): number {
  return Math.max(1, Math.ceil(total / IMPORT_ROWS_PER_PAGE));
}

/** The first and the last row shown on a page (from 1); both 0 when there is none. */
export function previewRange(page: number, total: number): { from: number; to: number } {
  if (total <= 0) return { from: 0, to: 0 };

  const from = (page - 1) * IMPORT_ROWS_PER_PAGE + 1;

  return { from, to: Math.min(page * IMPORT_ROWS_PER_PAGE, total) };
}

/** A page that exists: the one asked for, kept between the first and the last. */
export function clampPage(page: number, total: number): number {
  return Math.min(Math.max(1, page), previewPages(total));
}

const REASONS = [
  'full_name_required',
  'full_name_max',
  'group_max',
  'identifier_max',
  'email_invalid',
  'email_max',
  'phone_invalid',
  'phone_max',
  'group_limit_reached',
  'duplicate_identifier_in_file',
  'duplicate_email_in_file',
  'identifier_exists',
  'email_exists',
] as const;

/** The message key of a reason code; an unknown code gets the generic words, never the code. */
export function reasonKey(code: string): string {
  return (REASONS as readonly string[]).includes(code)
    ? `voterImport.check.reason.${code}`
    : 'voterImport.check.reason.unknown';
}

/**
 * The message keys that say a row's state: `Prêt` for a valid row, else one key per reason
 * (without repeats), or the generic words when the API gave no reason.
 */
export function rowStatusKeys(row: Pick<ImportRow, 'status' | 'reasons'>): string[] {
  if (row.status === 'ok') return ['voterImport.check.status.ok'];

  const keys = [...new Set(row.reasons.map(reasonKey))];

  return keys.length > 0
    ? keys
    : [
        row.status === 'duplicate'
          ? 'voterImport.check.status.duplicate'
          : 'voterImport.check.reason.unknown',
      ];
}

const FAILURES = [
  'no_header',
  'no_full_name_column',
  'too_many_rows',
  'unreadable',
  'import_failed',
] as const;

/** The message key of a failure code; an unknown code gets the generic words. */
export function failureKey(code: string | null): string {
  return code !== null && (FAILURES as readonly string[]).includes(code)
    ? `voterImport.check.failure.${code}`
    : 'voterImport.check.failure.unknown';
}

/** The message key of an action refused by the API (409), or null for the generic text. */
export function actionErrorKey(error: ApiError): string | null {
  const known = [
    'import_not_checked',
    'import_in_progress',
    'nothing_to_import',
    'voter_limit_reached',
    'election_voters_locked',
    'no_error_file',
    'not_found',
  ];

  return known.includes(error.code) ? `errors.${error.code}` : null;
}

/** Whether the mapping is one the API accepts: a name, columns in range, none used twice. */
export function validateMapping(
  mapping: Partial<ImportMapping>,
  columnCount: number,
): 'full_name_required' | 'out_of_range' | 'column_used_twice' | null {
  const used = new Set<number>();

  if (mapping.full_name === null || mapping.full_name === undefined) return 'full_name_required';

  for (const field of MAPPING_FIELDS) {
    const index = mapping[field];

    if (index === null || index === undefined) continue;
    if (!Number.isInteger(index) || index < 0 || index >= columnCount) return 'out_of_range';
    if (used.has(index)) return 'column_used_twice';

    used.add(index);
  }

  return null;
}

/** The value of a select (`''` for "do not import") as the API takes it. */
export function columnOfValue(value: string): number | null {
  if (value === '') return null;

  const index = Number.parseInt(value, 10);

  return Number.isInteger(index) && index >= 0 ? index : null;
}

/** The select value of a mapping entry. */
export function valueOfColumn(index: number | null | undefined): string {
  return index === null || index === undefined ? '' : String(index);
}

/**
 * The body of the PATCH for one field set to a column: `null` when nothing changes (the same
 * column again), so no call and no new check are made.
 */
export function mappingChange(
  current: Partial<ImportMapping> | null,
  field: MappingField,
  index: number | null,
): Partial<ImportMapping> | null {
  const before = current?.[field] ?? null;

  return before === index ? null : { [field]: index };
}

/** The mapping with the change applied, to be judged before it is sent. */
export function applyChange(
  current: Partial<ImportMapping> | null,
  change: Partial<ImportMapping>,
): Partial<ImportMapping> {
  return { ...current, ...change };
}

/** The fields whose column another field already uses (their options are not offered). */
export function takenColumns(
  mapping: Partial<ImportMapping> | null,
  except: MappingField,
): Set<number> {
  const taken = new Set<number>();

  for (const field of MAPPING_FIELDS) {
    const index = mapping?.[field];

    if (field !== except && index !== null && index !== undefined) taken.add(index);
  }

  return taken;
}
