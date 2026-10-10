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
