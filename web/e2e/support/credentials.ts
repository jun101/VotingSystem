import { expect, type Page } from '@playwright/test';

/*
 * Slice 10 helpers: the state of the codes and the files through the API. Part of the acceptance harness.
 */

export type CredentialGroup = {
  group: { id: string; name: string } | null;
  voters: number;
  with_code: number;
  with_email: number;
  files_ready: number;
};

export type CredentialState = {
  voters: number;
  with_code: number;
  without_code: number;
  with_email: number;
  run_in_progress: boolean;
  groups: CredentialGroup[];
};

export type GeneratedFile = {
  id: string;
  kind: string;
  group: { id: string; name: string } | null;
  part: number;
  parts: number;
  voter_count: number;
  status: 'queued' | 'ready' | 'failed';
  size_bytes: number | null;
};

/** What the codes page of an election reads. */
export async function readCredentials(page: Page, election: string): Promise<CredentialState> {
  const response = await page.request.get(`/api/v1/elections/${election}/credentials`);
  expect(response.status(), await response.text()).toBe(200);

  return ((await response.json()) as { data: CredentialState }).data;
}

/** The files of an election, newest first. */
export async function readFiles(page: Page, election: string): Promise<GeneratedFile[]> {
  const response = await page.request.get(`/api/v1/elections/${election}/generated-files`);
  expect(response.status(), await response.text()).toBe(200);

  return ((await response.json()) as { data: GeneratedFile[] }).data;
}

/** The pattern of a code as shown: ten characters, no 0 O 1 I L, two groups of five. */
export const CODE = /^[A-HJKMNP-Z2-9]{5}-[A-HJKMNP-Z2-9]{5}$/;
