import { expect, type Page } from '@playwright/test';

/*
 * Slice 08 helpers: voter files and imports through the API. Part of the acceptance harness.
 */

export type VoterImport = {
  id: string;
  election: string;
  status: 'uploaded' | 'checked' | 'importing' | 'done' | 'failed';
  columns: string[];
  mapping: Record<string, number | null>;
  total_rows: number;
  valid_rows: number;
  invalid_rows: number;
  duplicate_rows: number;
  new_groups: string[];
  imported_rows: number;
  failure: string | null;
};

export type FileToUpload = { name: string; mimeType: string; buffer: Buffer };

/** A CSV file (separator `;`, a byte-order mark as Excel writes it) from rows of cells. */
export function csvFile(rows: string[][], name = 'eleves.csv'): FileToUpload {
  const text = rows.map((row) => row.map((cell) => (/[;"\n]/.test(cell) ? `"${cell.replace(/"/g, '""')}"` : cell)).join(';')).join('\r\n');

  return { name, mimeType: 'text/csv', buffer: Buffer.from(`﻿${text}\r\n`, 'utf8') };
}

/** The header of the template. */
export const HEADER = ['nom_complet', 'groupe', 'identifiant', 'courriel', 'telephone'];

/** A file of the template header and these rows. */
export function templateFile(rows: string[][], name = 'eleves.csv'): FileToUpload {
  return csvFile([HEADER, ...rows], name);
}

/** The imports of an election, read through the API: the uuids found by uploading is the page's job, so this reads one. */
export async function readImport(page: Page, id: string): Promise<VoterImport> {
  const response = await page.request.get(`/api/v1/voter-imports/${id}`);
  expect(response.status(), await response.text()).toBe(200);

  return ((await response.json()) as { data: VoterImport }).data;
}

/** The import id in the address of the check page (`/voters/import/{id}`). */
export function importIdOf(url: string): string {
  const match = /\/voters\/import\/([0-9a-f-]{36})/.exec(url);
  expect(match, `no import id in ${url}`).not.toBeNull();

  return match![1];
}
