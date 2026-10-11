import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import * as browser from '@/lib/api/browser';
import type { CredentialState, GeneratedFile } from '@/lib/api/credentials';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError } from '@/lib/api/errors';
import { CodesPage, POLL_MS } from './CodesPage';

const refresh = vi.fn();

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh }),
}));

vi.mock('@/lib/api/browser', () => ({
  fetchCredentials: vi.fn(),
  fetchGeneratedFiles: vi.fn(),
  startCredentialRun: vi.fn(),
  downloadGeneratedFile: vi.fn(),
  reissueCredential: vi.fn(),
  fetchVoters: vi.fn(),
}));

const api = vi.mocked(browser);

const G4 = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };

function election(status: ElectionStatus): Election {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    title: 'Conseil des élèves 2026',
    description: null,
    status,
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
    groups: [
      {
        group: G4,
        voters: 3,
        with_code: 0,
        with_email: 0,
        email_queued: 0,
        email_sent: 0,
        email_failed: 0,
        files_ready: 0,
      },
      {
        group: null,
        voters: 2,
        with_code: 0,
        with_email: 0,
        email_queued: 0,
        email_sent: 0,
        email_failed: 0,
        files_ready: 0,
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

function show(
  status: ElectionStatus = 'draft',
  initial: CredentialState = state(),
  files: GeneratedFile[] = [],
  locale: 'fr' | 'en' = 'fr',
) {
  return renderIn(
    locale,
    <CodesPage election={election(status)} initialState={initial} initialFiles={files} />,
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
});

afterEach(() => {
  vi.useRealTimers();
});

describe('CodesPage', () => {
  it('shows the totals and one card per group, the ungrouped one as none (fr)', () => {
    show();

    expect(screen.getByTestId('codes-total-voters')).toHaveTextContent('5');
    expect(screen.getByTestId('codes-total-with-code')).toHaveTextContent('0');
    expect(screen.getByTestId('codes-total-without-code')).toHaveTextContent('5');
    expect(screen.getByTestId(`codes-group-${G4.id}`)).toHaveTextContent('4e année');
    expect(screen.getByTestId('codes-group-none')).toHaveTextContent('Sans groupe');
    expect(screen.getByTestId(`codes-group-${G4.id}-generate`)).toHaveTextContent(
      'Générer 3 coupons',
    );
    expect(screen.queryByTestId(`codes-group-${G4.id}-download`)).not.toBeInTheDocument();
    expect(screen.queryByTestId('codes-locked-notice')).not.toBeInTheDocument();
    expect(screen.queryByTestId('codes-run-status')).not.toBeInTheDocument();
  });

  it('shows the same page in English', () => {
    show('open', state(), [], 'en');

    expect(screen.getByTestId('codes-group-none')).toHaveTextContent('No group');
    expect(screen.getByTestId('codes-lost')).toHaveTextContent('Lost slip');
  });

  it('runs one group with its UUID, and the voters with no group as ungrouped', async () => {
    api.startCredentialRun.mockResolvedValue({
      files: [file({ status: 'queued' })],
      voters: 3,
      skipped: 0,
      emails: 0,
    });
    api.fetchCredentials.mockResolvedValue(state({ run_in_progress: true }));
    api.fetchGeneratedFiles.mockResolvedValue([file({ status: 'queued' })]);
    show();

    fireEvent.click(screen.getByTestId(`codes-group-${G4.id}-generate`));

    await waitFor(() => expect(screen.getByTestId('codes-run-status')).toBeInTheDocument());
    expect(api.startCredentialRun).toHaveBeenCalledWith('00000000-0000-4000-8000-000000000001', {
      scope: 'group',
      group: G4.id,
    });
    expect(screen.getByTestId('codes-generate-all')).toBeDisabled();
    expect(screen.getByTestId('codes-group-none-generate')).toBeDisabled();
  });

  it('generates for everyone with scope all', async () => {
    api.startCredentialRun.mockResolvedValue({
      files: [file({ status: 'queued' })],
      voters: 5,
      skipped: 0,
      emails: 0,
    });
    api.fetchCredentials.mockResolvedValue(state({ run_in_progress: true }));
    api.fetchGeneratedFiles.mockResolvedValue([file({ status: 'queued' })]);
    show();

    fireEvent.click(screen.getByTestId('codes-generate-all'));

    await waitFor(() => expect(api.startCredentialRun).toHaveBeenCalled());
    expect(api.startCredentialRun).toHaveBeenCalledWith('00000000-0000-4000-8000-000000000001', {
      scope: 'all',
    });
  });

  it('disables every generate button when every voter has a code', () => {
    show(
      'draft',
      state({
        with_code: 5,
        without_code: 0,
        groups: [
          {
            group: G4,
            voters: 3,
            with_code: 3,
            with_email: 0,
            email_queued: 0,
            email_sent: 0,
            email_failed: 0,
            files_ready: 1,
          },
          {
            group: null,
            voters: 2,
            with_code: 2,
            with_email: 0,
            email_queued: 0,
            email_sent: 0,
            email_failed: 0,
            files_ready: 0,
          },
        ],
      }),
      [file()],
    );

    expect(screen.getByTestId('codes-generate-all')).toBeDisabled();
    expect(screen.getByTestId(`codes-group-${G4.id}-generate`)).toBeDisabled();
    expect(screen.getByTestId(`codes-group-${G4.id}-download`)).toBeInTheDocument();
    expect(screen.queryByTestId('codes-group-none-download')).not.toBeInTheDocument();
  });

  it('downloads the first ready file with the name the API sends', async () => {
    const saved = vi.fn();
    const create = vi.fn(() => 'blob:x');
    URL.createObjectURL = create;
    URL.revokeObjectURL = vi.fn();
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(saved);
    api.downloadGeneratedFile.mockResolvedValue({
      blob: new Blob(['%PDF-']),
      name: 'coupons-4e-annee.pdf',
    });
    show('draft', state({ with_code: 3, without_code: 2 }), [file()]);

    fireEvent.click(screen.getByTestId(`codes-group-${G4.id}-download`));

    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(api.downloadGeneratedFile).toHaveBeenCalledWith('50000000-0000-4000-8000-000000000001');
    click.mockRestore();
  });

  it('shows a link per part when a group was split', () => {
    show('draft', state({ with_code: 3, without_code: 2 }), [
      file({ id: '50000000-0000-4000-8000-000000000002', part: 2, parts: 2 }),
      file({ id: '50000000-0000-4000-8000-000000000001', part: 1, parts: 2 }),
    ]);

    expect(screen.getByTestId(`codes-group-${G4.id}-download`)).toHaveTextContent('Partie 1 sur 2');
    expect(screen.getByTestId(`codes-group-${G4.id}-file-2`)).toHaveTextContent('Partie 2 sur 2');
  });

  it('lists a reissued one-slip file beside the run file, newest first, with its slips and date', () => {
    show('draft', state({ with_code: 3, without_code: 2 }), [
      file({ id: '50000000-0000-4000-8000-000000000001', voter_count: 3 }),
      file({
        id: '50000000-0000-4000-8000-000000000002',
        voter_count: 1,
        created_at: '2099-01-01T10:00:00Z',
      }),
    ]);

    expect(screen.getByTestId(`codes-group-${G4.id}-file-1`)).toHaveTextContent('1 coupon');
    expect(screen.getByTestId(`codes-group-${G4.id}-download`)).toHaveTextContent('1 coupon');
    expect(screen.getByTestId(`codes-group-${G4.id}-file-2`)).toHaveTextContent('3 coupons');
  });

  it('polls while a file is queued, then says how many voters got a code', async () => {
    vi.useFakeTimers();
    api.fetchCredentials.mockResolvedValue(state({ with_code: 3, without_code: 2 }));
    api.fetchGeneratedFiles.mockResolvedValue([file()]);
    show('draft', state({ run_in_progress: true }), [file({ status: 'queued' })]);

    expect(screen.getByTestId('codes-run-status')).toBeInTheDocument();
    expect(screen.queryByTestId('codes-run-result')).not.toBeInTheDocument();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS + 10);
    });

    expect(api.fetchGeneratedFiles).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('codes-run-status')).not.toBeInTheDocument();
    expect(screen.getByTestId('codes-run-result')).toHaveTextContent(
      '3 électeurs ont reçu leur code',
    );
    expect(screen.getByTestId('codes-total-with-code')).toHaveTextContent('3');
    expect(screen.getByTestId(`codes-group-${G4.id}-download`)).toBeInTheDocument();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS * 3);
    });

    // Nothing is queued any more: no more reading.
    expect(api.fetchGeneratedFiles).toHaveBeenCalledTimes(1);
  });

  it('keeps the buttons usable and reads slowly while only messages wait to be sent', async () => {
    vi.useFakeTimers();
    api.fetchCredentials.mockResolvedValue(state({ email_queued: 2 }));
    api.fetchGeneratedFiles.mockResolvedValue([file()]);
    show('draft', state({ run_in_progress: true, email_queued: 2 }), [file()]);

    expect(screen.queryByTestId('codes-run-status')).not.toBeInTheDocument();
    expect(screen.getByTestId('codes-generate-all')).toBeEnabled();
    expect(screen.getByTestId('codes-send')).toBeEnabled();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS + 10);
    });
    expect(api.fetchGeneratedFiles).not.toHaveBeenCalled();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5000);
    });
    expect(api.fetchGeneratedFiles).toHaveBeenCalledTimes(1);
  });

  it('tells how many files failed', async () => {
    vi.useFakeTimers();
    api.fetchCredentials.mockResolvedValue(state());
    api.fetchGeneratedFiles.mockResolvedValue([
      file({ status: 'failed', failure: 'generation_failed' }),
    ]);
    show('draft', state({ run_in_progress: true }), [file({ status: 'queued' })]);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(POLL_MS + 10);
    });

    expect(screen.getByTestId('codes-run-result')).toHaveTextContent('1 fichier a échoué');
  });

  it.each<ElectionStatus>(['closed', 'published', 'archived'])(
    'is read-only in a %s election',
    (status) => {
      show(status, state({ with_code: 3, without_code: 2 }), [file()]);

      expect(screen.getByTestId('codes-locked-notice')).toBeInTheDocument();
      expect(screen.queryByTestId('codes-generate-all')).not.toBeInTheDocument();
      expect(screen.queryByTestId('codes-lost')).not.toBeInTheDocument();
      expect(screen.queryByTestId(`codes-group-${G4.id}-generate`)).not.toBeInTheDocument();
      expect(screen.getByTestId(`codes-group-${G4.id}-count`)).toHaveTextContent('0');
    },
  );

  it('turns to the locked view when the server says the election is locked', async () => {
    api.startCredentialRun.mockRejectedValue(new ApiError(409, 'election_codes_locked'));
    api.fetchCredentials.mockResolvedValue(state());
    api.fetchGeneratedFiles.mockResolvedValue([]);
    show();

    fireEvent.click(screen.getByTestId('codes-generate-all'));

    await waitFor(() => expect(screen.getByTestId('codes-locked-notice')).toBeInTheDocument());
    expect(screen.queryByTestId('codes-generate-all')).not.toBeInTheDocument();
    expect(refresh).toHaveBeenCalled();
  });

  it('says a refusal in words and keeps the buttons', async () => {
    api.startCredentialRun.mockRejectedValue(new ApiError(429, 'too_many_attempts'));
    show();

    fireEvent.click(screen.getByTestId('codes-generate-all'));

    await waitFor(() => expect(screen.getByTestId('codes-notice')).toBeInTheDocument());
    expect(screen.getByTestId('codes-generate-all')).not.toBeDisabled();
  });

  it('opens the lost-slip dialog from the page', () => {
    show();

    fireEvent.click(screen.getByTestId('codes-lost'));

    expect(screen.getByTestId('lost-search')).toBeInTheDocument();
    expect(screen.getByTestId('lost-confirm')).toBeDisabled();
  });
});
