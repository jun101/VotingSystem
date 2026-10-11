import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import * as browser from '@/lib/api/browser';
import type { CredentialState, GeneratedFile } from '@/lib/api/credentials';
import type { Election } from '@/lib/api/elections';
import { CodesPage } from './CodesPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/lib/api/browser', () => ({
  fetchCredentials: vi.fn(),
  fetchGeneratedFiles: vi.fn(),
  startCredentialRun: vi.fn(),
  previewCredentialRun: vi.fn(),
  downloadGeneratedFile: vi.fn(),
  reissueCredential: vi.fn(),
  fetchVoters: vi.fn(),
}));

const api = vi.mocked(browser);
const G4 = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };

const ELECTION: Election = {
  id: '00000000-0000-4000-8000-000000000001',
  title: 'Conseil des élèves 2026',
  description: null,
  status: 'draft',
  starts_at: '2026-10-12T12:00:00Z',
  ends_at: '2026-10-16T19:00:00Z',
  timezone: 'America/Port-au-Prince',
  language: 'fr',
  candidate_order: 'manual',
  results_display: 'full',
  cover: null,
  ballots_count: 1,
  voters_count: 5,
  created_at: '2026-10-08T15:20:00Z',
};

function state(over: Partial<CredentialState> = {}): CredentialState {
  return {
    voters: 3,
    with_code: 3,
    without_code: 0,
    with_email: 3,
    email_queued: 0,
    email_sent: 2,
    email_failed: 1,
    run_in_progress: false,
    groups: [
      {
        group: G4,
        voters: 3,
        with_code: 3,
        with_email: 3,
        email_queued: 0,
        email_sent: 2,
        email_failed: 1,
        files_ready: 1,
      },
    ],
    ...over,
  };
}

function file(over: Partial<GeneratedFile> = {}): GeneratedFile {
  return {
    id: '50000000-0000-4000-8000-000000000001',
    kind: 'slips_pdf',
    group: G4,
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

function show(initial = state(), files: GeneratedFile[] = []) {
  return renderIn(
    'fr',
    <CodesPage election={ELECTION} initialState={initial} initialFiles={files} />,
  );
}

beforeAll(() => {
  HTMLDialogElement.prototype.showModal = function showModal() {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function close() {
    this.removeAttribute('open');
  };
});

beforeEach(() => {
  vi.clearAllMocks();
  api.previewCredentialRun.mockResolvedValue({
    to_generate: 1,
    with_email: 1,
    without_email: 0,
    skipped: 0,
    voters_without_email: [],
  });
});

describe('CodesPage email status', () => {
  it('shows the totals and the counts of each group', () => {
    show();

    expect(screen.getByTestId('codes-total-email-sent')).toHaveTextContent('2');
    expect(screen.getByTestId('codes-total-email-failed')).toHaveTextContent('1');
    expect(screen.getByTestId(`codes-group-${G4.id}-email`)).toHaveTextContent('2 envoyés');
    expect(screen.getByTestId(`codes-group-${G4.id}-email`)).toHaveTextContent('1 en échec');
  });

  it('shows nothing about email when no message exists', () => {
    show(
      state({
        email_sent: 0,
        email_failed: 0,
        groups: [{ ...state().groups[0]!, email_sent: 0, email_failed: 0 }],
      }),
    );

    expect(screen.queryByTestId('codes-total-email-sent')).not.toBeInTheDocument();
    expect(screen.queryByTestId(`codes-group-${G4.id}-email`)).not.toBeInTheDocument();
    expect(screen.queryByTestId('codes-resend-failed')).not.toBeInTheDocument();
  });

  it('opens the dialog on the failed emails from the resend button', async () => {
    show();

    fireEvent.click(screen.getByTestId('codes-resend-failed'));

    expect(screen.getByTestId('send-dialog')).toBeInTheDocument();
    expect(screen.getByTestId('send-scope')).toHaveValue('failed_email');
    expect(screen.getByTestId('send-email')).toBeChecked();
    await waitFor(() => expect(api.previewCredentialRun).toHaveBeenCalled());
  });

  it('opens the dialog from the generate and send button', async () => {
    show();

    fireEvent.click(screen.getByTestId('codes-send'));

    expect(screen.getByTestId('send-scope')).toHaveValue('all');
    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());
  });

  it('lists the code files apart from the slips, newest first', () => {
    show(state(), [
      file(),
      file({
        id: '50000000-0000-4000-8000-000000000002',
        kind: 'codes_csv',
        group: null,
        created_at: '2026-10-11T08:00:00Z',
      }),
      file({
        id: '50000000-0000-4000-8000-000000000003',
        kind: 'codes_xlsx',
        group: null,
        created_at: '2026-10-10T08:00:00Z',
      }),
    ]);

    expect(screen.getByTestId('codes-file-1')).toHaveTextContent('CSV');
    expect(screen.getByTestId('codes-file-2')).toHaveTextContent('Excel');
    expect(screen.queryByTestId('codes-file-3')).not.toBeInTheDocument();
    // The group card keeps only its own slip.
    expect(screen.getByTestId(`codes-group-${G4.id}-file-1`)).toBeInTheDocument();
    expect(screen.queryByTestId(`codes-group-${G4.id}-file-2`)).not.toBeInTheDocument();
  });

  it('downloads a code file with the name the API gave', async () => {
    api.downloadGeneratedFile.mockResolvedValue({
      blob: new Blob(['x']),
      name: 'codes-tous.xlsx',
    });
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    show(state(), [file({ kind: 'codes_xlsx', group: null })]);

    fireEvent.click(screen.getByTestId('codes-file-1'));

    await waitFor(() =>
      expect(api.downloadGeneratedFile).toHaveBeenCalledWith(
        '50000000-0000-4000-8000-000000000001',
      ),
    );
  });
});
