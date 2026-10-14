/*
 * The rules of the send dialog and of the email status on A11 (docs/slices/11-email-and-file.md):
 * which scopes are offered, what is previewed and sent, when the confirm button is pressable, and
 * what the status block says. The server judges everything again; this keeps the page from
 * offering what it refuses. No text here, no browser call.
 */

import type {
  CredentialLine,
  CredentialPreview,
  CredentialState,
  GeneratedFile,
  PreviewBody,
  RunBody,
} from '@/lib/api/credentials';

/** What the voters left for print are listed up to (the API sends 100 at most). */
export const NO_EMAIL_LIST_MAX = 100;

/** The file written with a run: none, Excel or CSV. */
export type SendFile = '' | 'xlsx' | 'csv';

/** What the person chose in the dialog. `scope` is `all`, `ungrouped`, `failed_email` or a group's UUID. */
export type SendDraft = { scope: string; email: boolean; file: SendFile };

export const EMPTY_SEND: SendDraft = { scope: 'all', email: false, file: '' };

/** One choice of the scope select: the value and, for a group, its name. */
export type ScopeOption =
  { value: 'all' | 'ungrouped' | 'failed_email' } | { value: string; name: string };

/**
 * The scopes offered: every voter, each group by name, the voters with no group (only when there
 * are some), and the failed emails (only when a message failed).
 */
export function scopeOptions(state: CredentialState): ScopeOption[] {
  const options: ScopeOption[] = [{ value: 'all' }];

  for (const line of state.groups) {
    if (line.group) options.push({ value: line.group.id, name: line.group.name });
  }

  if (state.groups.some((line) => line.group === null && line.voters > 0)) {
    options.push({ value: 'ungrouped' });
  }

  if (state.email_failed > 0) options.push({ value: 'failed_email' });

  return options;
}

/** The scope the dialog opens on: the failed emails when asked, else every voter. */
export function draftFor(failedOnly: boolean): SendDraft {
  return failedOnly ? { scope: 'failed_email', email: true, file: '' } : EMPTY_SEND;
}

/** The body of the preview for a scope. */
export function previewBodyOf(scope: string): PreviewBody {
  if (scope === 'all' || scope === 'ungrouped' || scope === 'failed_email') return { scope };

  return { scope: 'group', group: scope };
}

/** Resending the failed messages always sends: a new code with no email would help nobody. */
export function emailForced(draft: SendDraft): boolean {
  return draft.scope === 'failed_email';
}

/** Whether a message is sent for this draft. */
export function emailOn(draft: SendDraft): boolean {
  return draft.email || emailForced(draft);
}

/** The count the person confirms: the voters with an address, when emails are sent. */
export function confirmCount(draft: SendDraft, preview: CredentialPreview | null): number | null {
  return emailOn(draft) && preview ? preview.with_email : null;
}

/**
 * The confirm button works once the numbers are read, something would get a code, and, with
 * emails, at least one voter has an address (the server refuses the others with
 * `nothing_to_email`).
 */
export function canConfirm(
  draft: SendDraft,
  preview: CredentialPreview | null,
  busy: boolean,
): boolean {
  if (busy || preview === null || preview.to_generate <= 0) return false;

  return !emailOn(draft) || preview.with_email > 0;
}

/** The body of the run: the scope, the channels, and the count the person saw. */
export function runBodyFor(draft: SendDraft, preview: CredentialPreview): RunBody {
  const body: RunBody =
    draft.scope === 'all' || draft.scope === 'ungrouped' || draft.scope === 'failed_email'
      ? { scope: draft.scope }
      : { scope: 'group', group: draft.scope };

  if (emailOn(draft)) {
    body.email = true;
    body.confirm_emails = preview.with_email;
  }

  if (draft.file !== '') body.file = draft.file;

  return body;
}

/** The voters left for print that are listed, and how many more there are than the list holds. */
export function noEmailMore(preview: CredentialPreview): number {
  return Math.max(0, preview.without_email - preview.voters_without_email.length);
}

/** The email counts of the totals or of a card. */
export type EmailSummary = { queued: number; sent: number; failed: number; any: boolean };

export function emailSummary(
  line: Pick<CredentialLine, 'email_queued' | 'email_sent' | 'email_failed'>,
): EmailSummary {
  const { email_queued: queued, email_sent: sent, email_failed: failed } = line;

  return { queued, sent, failed, any: queued + sent + failed > 0 };
}

/** The resend button is offered while a message failed, the codes can change and no run is going on. */
export function canResend(state: CredentialState, running: boolean, locked: boolean): boolean {
  return !locked && !running && state.email_failed > 0;
}

/** The ready self-distribution files (Excel or CSV), newest first. */
export function codeFilesOf(files: readonly GeneratedFile[]): GeneratedFile[] {
  return files
    .filter(
      (file) =>
        (file.kind === 'codes_xlsx' || file.kind === 'codes_csv') && file.status === 'ready',
    )
    .sort((a, b) => b.created_at.localeCompare(a.created_at));
}
