/*
 * What the template file holds (docs/api/voter-imports/GET-elections-{election}-voter-imports-template.md):
 * the header and the three example rows, as the page shows them before the download.
 */

export const TEMPLATE_COLUMNS = [
  'nom_complet',
  'groupe',
  'identifiant',
  'courriel',
  'telephone',
] as const;

export type TemplateColumn = (typeof TEMPLATE_COLUMNS)[number];

/** Only the full name is required. */
export const REQUIRED_COLUMN: TemplateColumn = 'nom_complet';

/** Example cells, one array of five per row, in the order of `TEMPLATE_COLUMNS`. */
export const TEMPLATE_ROWS: readonly (readonly string[])[] = [
  ['Rose-Marie Désir', '4e année', 'E-2041', 'rm.desir@example.ht', '+509 3712 4455'],
  ['Jean-Baptiste Pierre', '4e année', 'E-2042', '', '+509 2813 0000'],
  ['Nadège Louis', '5e année', 'E-2107', '', ''],
];
