import type { operations } from './schema';

/** What the page needs of the import the upload created: its UUID (`id` in the API). */
export type VoterImportUpload = { id: string };

/** The file formats the template comes in. */
export type TemplateFormat = 'xlsx' | 'csv';

/** The address of the template: a plain download, same origin through the proxy. */
export function templateUrl(election: string, format: TemplateFormat): string {
  return `/api/v1/elections/${encodeURIComponent(election)}/voter-imports/template?format=${format}`;
}

/** At most 5 MB, at most 10 000 rows: said to the person, judged by the server. */
export const IMPORT_MAX_BYTES = 5 * 1024 * 1024;
export const IMPORT_MAX_ROWS = 10000;

type ShowImport = operations['voterImport.show']['responses'][200]['content']['application/json'];
type RowsOfImport = operations['voterImport.rows']['responses'][200]['content']['application/json'];

/** An import: `GET /voter-imports/{import}`. Its `id` and `election` are UUIDs. */
export type VoterImport = ShowImport['data'];

export type VoterImportStatus = VoterImport['status'];

/** One row of the preview: an item of `GET /voter-imports/{import}/rows`. */
export type ImportRow = RowsOfImport['data'][number];

/** The rows asked for: all of them, the valid ones, or every rejected one. */
export type ImportRowFilter = 'all' | 'ok' | 'errors';

/** One page of the preview. */
export type ImportRowList = { items: ImportRow[]; total: number; page: number };

/** The voter fields a column can be given to. */
export const MAPPING_FIELDS = ['full_name', 'group', 'identifier', 'email', 'phone'] as const;

export type MappingField = (typeof MAPPING_FIELDS)[number];

/** A column index (from 0) of the file per field, or `null` for "do not import". */
export type ImportMapping = Record<MappingField, number | null>;

/** The preview holds 50 rows a page. */
export const IMPORT_ROWS_PER_PAGE = 50;

/** The address of the rejected rows: a plain download, same origin through the proxy. */
export function errorFileUrl(importId: string): string {
  return `/api/v1/voter-imports/${encodeURIComponent(importId)}/errors`;
}
