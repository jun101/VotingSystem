import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import * as browser from '@/lib/api/browser';
import type { CredentialPreview, CredentialState } from '@/lib/api/credentials';
import { ApiError } from '@/lib/api/errors';
import { SendDialog } from './SendDialog';
import { EMPTY_SEND, draftFor, type SendDraft } from './sendRules';

vi.mock('@/lib/api/browser', () => ({
  previewCredentialRun: vi.fn(),
  startCredentialRun: vi.fn(),
}));

const api = vi.mocked(browser);
const ELECTION = '00000000-0000-4000-8000-000000000001';
const G4 = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };

function state(over: Partial<CredentialState> = {}): CredentialState {
  return {
    voters: 3,
    with_code: 0,
    without_code: 3,
    with_email: 2,
    email_queued: 0,
    email_sent: 0,
    email_failed: 0,
    run_in_progress: false,
    groups: [
      {
        group: G4,
        voters: 3,
        with_code: 0,
        with_email: 2,
        email_queued: 0,
        email_sent: 0,
        email_failed: 0,
        files_ready: 0,
      },
    ],
    ...over,
  };
}

function preview(over: Partial<CredentialPreview> = {}): CredentialPreview {
  return {
    to_generate: 3,
    with_email: 2,
    without_email: 1,
    skipped: 0,
    voters_without_email: [
      { id: '20000000-0000-4000-8000-000000000003', full_name: 'Nadège Louis', group: G4 },
    ],
    ...over,
  };
}

const RUN = { files: [], voters: 3, skipped: 0, emails: 2 };

function show(initial: SendDraft = EMPTY_SEND, over: Partial<CredentialState> = {}) {
  const onStarted = vi.fn();
  const onClose = vi.fn();
  const onLocked = vi.fn();

  renderIn(
    'fr',
    <SendDialog
      election={ELECTION}
      state={state(over)}
      initial={initial}
      onStarted={onStarted}
      onLocked={onLocked}
      onClose={onClose}
    />,
  );

  return { onStarted, onClose, onLocked };
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
  api.previewCredentialRun.mockResolvedValue(preview());
});

describe('SendDialog', () => {
  it('reads the numbers when it opens and keeps confirm disabled until they are read', async () => {
    show();

    expect(screen.getByTestId('send-confirm')).toBeDisabled();
    await waitFor(() => expect(screen.getByTestId('send-count-generate')).toHaveTextContent('3'));
    expect(api.previewCredentialRun).toHaveBeenCalledWith(ELECTION, { scope: 'all' });
    expect(screen.getByTestId('send-count-email')).toHaveTextContent('2');
    expect(screen.getByTestId('send-count-no-email')).toHaveTextContent('1');
    expect(screen.getByTestId('send-count-skipped')).toHaveTextContent('0');
    expect(screen.getByTestId('send-confirm')).toBeEnabled();
  });

  it('lists the voters left for print once emails are chosen', async () => {
    show();
    await waitFor(() => expect(screen.getByTestId('send-count-email')).toHaveTextContent('2'));

    expect(screen.queryByTestId('send-no-email-list')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('send-email'));

    expect(screen.getByTestId('send-no-email-list')).toHaveTextContent('Nadège Louis');
    expect(screen.queryByTestId('send-no-email-more')).not.toBeInTheDocument();
  });

  it('says how many more there are than the list holds', async () => {
    api.previewCredentialRun.mockResolvedValue(preview({ without_email: 130 }));
    show({ scope: 'all', email: true, file: '' });

    await waitFor(() =>
      expect(screen.getByTestId('send-no-email-more')).toHaveTextContent('et 129 autres'),
    );
  });

  it('reads the numbers again when the scope changes', async () => {
    show();
    await waitFor(() => expect(screen.getByTestId('send-count-generate')).toHaveTextContent('3'));
    api.previewCredentialRun.mockResolvedValue(preview({ to_generate: 1, with_email: 1 }));

    fireEvent.change(screen.getByTestId('send-scope'), { target: { value: G4.id } });

    await waitFor(() =>
      expect(api.previewCredentialRun).toHaveBeenLastCalledWith(ELECTION, {
        scope: 'group',
        group: G4.id,
      }),
    );
    await waitFor(() => expect(screen.getByTestId('send-count-generate')).toHaveTextContent('1'));
  });

  it('confirms with the email, the file and the count it showed', async () => {
    api.startCredentialRun.mockResolvedValue(RUN);
    const { onStarted, onClose } = show();
    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());

    fireEvent.click(screen.getByTestId('send-email'));
    fireEvent.change(screen.getByTestId('send-file'), { target: { value: 'xlsx' } });
    fireEvent.click(screen.getByTestId('send-confirm'));

    await waitFor(() => expect(api.startCredentialRun).toHaveBeenCalled());
    expect(api.startCredentialRun).toHaveBeenCalledWith(ELECTION, {
      scope: 'all',
      email: true,
      confirm_emails: 2,
      file: 'xlsx',
    });
    await waitFor(() => expect(onStarted).toHaveBeenCalledWith(RUN));
    expect(onClose).toHaveBeenCalled();
  });

  it('sends no email field when only generating', async () => {
    api.startCredentialRun.mockResolvedValue({ ...RUN, emails: 0 });
    show();
    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());

    fireEvent.click(screen.getByTestId('send-confirm'));

    await waitFor(() =>
      expect(api.startCredentialRun).toHaveBeenCalledWith(ELECTION, { scope: 'all' }),
    );
  });

  it('reads the numbers again on 409 email_count_changed and says so', async () => {
    api.startCredentialRun.mockRejectedValue(new ApiError(409, 'email_count_changed'));
    const { onStarted, onClose } = show({ scope: 'all', email: true, file: '' });
    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());
    api.previewCredentialRun.mockResolvedValue(preview({ with_email: 1 }));

    fireEvent.click(screen.getByTestId('send-confirm'));

    await waitFor(() => expect(screen.getByTestId('send-changed')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByTestId('send-count-email')).toHaveTextContent('1'));
    expect(api.previewCredentialRun).toHaveBeenCalledTimes(2);
    expect(onStarted).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
  });

  it('shows the message of another refusal and stays open', async () => {
    api.startCredentialRun.mockRejectedValue(new ApiError(409, 'nothing_to_email'));
    const { onClose } = show({ scope: 'all', email: true, file: '' });
    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());

    fireEvent.click(screen.getByTestId('send-confirm'));

    await waitFor(() => expect(screen.getByTestId('send-error')).toHaveTextContent('adresse'));
    expect(onClose).not.toHaveBeenCalled();
  });

  it('opens on the failed emails with the email checked and locked', async () => {
    show(draftFor(true), { email_failed: 2 });

    expect(screen.getByTestId('send-scope')).toHaveValue('failed_email');
    expect(screen.getByTestId('send-email')).toBeChecked();
    expect(screen.getByTestId('send-email')).toBeDisabled();
    await waitFor(() =>
      expect(api.previewCredentialRun).toHaveBeenCalledWith(ELECTION, { scope: 'failed_email' }),
    );
  });

  it('keeps confirm disabled when nothing would be generated', async () => {
    api.previewCredentialRun.mockResolvedValue(
      preview({
        to_generate: 0,
        with_email: 0,
        without_email: 0,
        skipped: 3,
        voters_without_email: [],
      }),
    );
    show();

    await waitFor(() => expect(screen.getByTestId('send-nothing')).toBeInTheDocument());
    expect(screen.getByTestId('send-confirm')).toBeDisabled();
  });

  it('shows a reading failure and reads again on request', async () => {
    api.previewCredentialRun.mockRejectedValueOnce(new ApiError(500, 'server_error'));
    show();

    await waitFor(() => expect(screen.getByTestId('send-read-failed')).toBeInTheDocument());
    expect(screen.getByTestId('send-confirm')).toBeDisabled();

    fireEvent.click(screen.getByTestId('send-read-retry'));

    await waitFor(() => expect(screen.getByTestId('send-confirm')).toBeEnabled());
  });
});
