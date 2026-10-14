/*
 * The pure parts of the import page (docs/slices/08-import.md, 08c). The server judges the file
 * and the status; this only decides what the page offers and which words a refusal gets.
 */

import type { ElectionStatus } from '@/lib/api/elections';
import type { ApiError } from '@/lib/api/errors';

/** Voters can be imported into a draft or a scheduled election only. */
export function importAllowed(status: ElectionStatus): boolean {
  return status === 'draft' || status === 'scheduled';
}

/** The rules of the field `file` the API names. */
const FILE_CODES = ['required', 'invalid_type', 'empty', 'too_large'] as const;

/**
 * The message key for a refused upload, or null when the generic error text should be used.
 * A 422 names the rule of `file`; a 409 names `import_in_progress` or `election_voters_locked`.
 */
export function uploadErrorKey(error: ApiError): string | null {
  if (error.status === 422) {
    const code = error.fields.file?.[0];

    if (code && (FILE_CODES as readonly string[]).includes(code)) {
      return `voterImport.error.file.${code}`;
    }

    return null;
  }

  if (error.code === 'import_in_progress') return 'errors.import_in_progress';
  if (error.code === 'election_voters_locked') return 'errors.election_voters_locked';

  return null;
}

/** The name shown for a chosen file: the name alone, never a path. */
export function fileLabel(name: string): string {
  const parts = name.split(/[\\/]/);

  return (parts[parts.length - 1] ?? '').trim();
}

/** Whether the name ends as a spreadsheet or a text file does: only a hint, never a refusal. */
export function hasVoterFileExtension(name: string): boolean {
  return /\.(xlsx|csv)$/i.test(fileLabel(name));
}
