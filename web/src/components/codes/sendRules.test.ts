import { describe, expect, it } from 'vitest';
import type {
  CredentialLine,
  CredentialPreview,
  CredentialState,
  GeneratedFile,
} from '@/lib/api/credentials';
import {
  EMPTY_SEND,
  canConfirm,
  canResend,
  codeFilesOf,
  confirmCount,
  draftFor,
  emailForced,
  emailOn,
  emailSummary,
  noEmailMore,
  previewBodyOf,
  runBodyFor,
  scopeOptions,
} from './sendRules';

const G4 = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };
const G5 = { id: '10000000-0000-4000-8000-000000000005', name: '5e année' };

function line(over: Partial<CredentialLine> = {}): CredentialLine {
  return {
    group: G4,
    voters: 3,
    with_code: 0,
    with_email: 0,
    email_queued: 0,
    email_sent: 0,
    email_failed: 0,
    files_ready: 0,
    ...over,
  };
}

function state(over: Partial<CredentialState> = {}): CredentialState {
  return {
    voters: 5,
    with_code: 0,
    without_code: 5,
    with_email: 0,
    email_queued: 0,
    email_sent: 0,
    email_failed: 0,
    run_in_progress: false,
    groups: [line(), line({ group: G5 }), line({ group: null, voters: 2 })],
    ...over,
  };
}

function preview(over: Partial<CredentialPreview> = {}): CredentialPreview {
  return {
    to_generate: 5,
    with_email: 3,
    without_email: 2,
    skipped: 0,
    voters_without_email: [],
    ...over,
  };
}

function file(over: Partial<GeneratedFile> = {}): GeneratedFile {
  return {
    id: '50000000-0000-4000-8000-000000000001',
    kind: 'codes_xlsx',
    group: null,
    part: 1,
    parts: 1,
    voter_count: 3,
    status: 'ready',
    failure: null,
    size_bytes: 1000,
    expires_at: '2026-10-20T00:00:00Z',
    created_at: '2026-10-10T19:12:05Z',
    ...over,
  };
}

describe('scopeOptions', () => {
  it('offers all, each group, and the voters with no group', () => {
    expect(scopeOptions(state()).map((o) => o.value)).toEqual(['all', G4.id, G5.id, 'ungrouped']);
  });

  it('leaves the ungrouped scope out when nobody is ungrouped', () => {
    const only = state({ groups: [line()] });

    expect(scopeOptions(only).map((o) => o.value)).toEqual(['all', G4.id]);
  });

  it('offers failed_email only when a message failed', () => {
    expect(scopeOptions(state()).some((o) => o.value === 'failed_email')).toBe(false);
    expect(scopeOptions(state({ email_failed: 2 })).at(-1)?.value).toBe('failed_email');
  });
});

describe('bodies', () => {
  it('previews a scope, with the group UUID for a group', () => {
    expect(previewBodyOf('all')).toEqual({ scope: 'all' });
    expect(previewBodyOf('ungrouped')).toEqual({ scope: 'ungrouped' });
    expect(previewBodyOf('failed_email')).toEqual({ scope: 'failed_email' });
    expect(previewBodyOf(G4.id)).toEqual({ scope: 'group', group: G4.id });
  });

  it('sends the scope alone when no channel is chosen', () => {
    expect(runBodyFor(EMPTY_SEND, preview())).toEqual({ scope: 'all' });
  });

  it('confirms the with_email count the person saw, and the file', () => {
    expect(
      runBodyFor({ scope: G4.id, email: true, file: 'csv' }, preview({ with_email: 7 })),
    ).toEqual({
      scope: 'group',
      group: G4.id,
      email: true,
      confirm_emails: 7,
      file: 'csv',
    });
  });

  it('forces the email on the failed_email scope', () => {
    const draft = draftFor(true);

    expect(draft).toEqual({ scope: 'failed_email', email: true, file: '' });
    expect(emailForced(draft)).toBe(true);
    expect(emailOn({ scope: 'failed_email', email: false, file: '' })).toBe(true);
    expect(runBodyFor({ scope: 'failed_email', email: false, file: '' }, preview())).toMatchObject({
      scope: 'failed_email',
      email: true,
      confirm_emails: 3,
    });
  });
});

describe('canConfirm and confirmCount', () => {
  it('waits for the numbers', () => {
    expect(canConfirm(EMPTY_SEND, null, false)).toBe(false);
    expect(canConfirm(EMPTY_SEND, preview(), false)).toBe(true);
  });

  it('is disabled when nothing would be generated, or while busy', () => {
    expect(canConfirm(EMPTY_SEND, preview({ to_generate: 0 }), false)).toBe(false);
    expect(canConfirm(EMPTY_SEND, preview(), true)).toBe(false);
  });

  it('needs an address among them when emails are sent', () => {
    const draft = { ...EMPTY_SEND, email: true };

    expect(canConfirm(draft, preview({ with_email: 0, without_email: 5 }), false)).toBe(false);
    expect(canConfirm(draft, preview(), false)).toBe(true);
    expect(canConfirm(EMPTY_SEND, preview({ with_email: 0 }), false)).toBe(true);
  });

  it('confirms a count only when sending emails', () => {
    expect(confirmCount(EMPTY_SEND, preview())).toBeNull();
    expect(confirmCount({ ...EMPTY_SEND, email: true }, preview())).toBe(3);
    expect(confirmCount({ ...EMPTY_SEND, email: true }, null)).toBeNull();
  });
});

describe('the status', () => {
  it('counts the three states and says whether any exists', () => {
    expect(emailSummary(line())).toEqual({ queued: 0, sent: 0, failed: 0, any: false });
    expect(emailSummary(line({ email_sent: 2, email_failed: 1 }))).toEqual({
      queued: 0,
      sent: 2,
      failed: 1,
      any: true,
    });
  });

  it('offers the resend only for failed messages, in an unlocked page with no run', () => {
    expect(canResend(state({ email_failed: 1 }), false, false)).toBe(true);
    expect(canResend(state(), false, false)).toBe(false);
    expect(canResend(state({ email_failed: 1 }), true, false)).toBe(false);
    expect(canResend(state({ email_failed: 1 }), false, true)).toBe(false);
  });

  it('counts the voters left out of the list', () => {
    const base = preview({ without_email: 130 });

    expect(noEmailMore(base)).toBe(130);
    expect(
      noEmailMore({
        ...base,
        voters_without_email: Array.from({ length: 100 }, (_, i) => ({
          id: `20000000-0000-4000-8000-${String(i).padStart(12, '0')}`,
          full_name: `Voter ${i}`,
          group: null,
        })),
      }),
    ).toBe(30);
  });
});

describe('codeFilesOf', () => {
  it('keeps the ready Excel and CSV files, newest first, and ignores the slips', () => {
    const files = [
      file({ id: 'a', created_at: '2026-10-10T10:00:00Z' }),
      file({ id: 'b', kind: 'codes_csv', created_at: '2026-10-11T10:00:00Z' }),
      file({ id: 'c', kind: 'slips_pdf' }),
      file({ id: 'd', status: 'queued' }),
    ];

    expect(codeFilesOf(files).map((f) => f.id)).toEqual(['b', 'a']);
  });
});
