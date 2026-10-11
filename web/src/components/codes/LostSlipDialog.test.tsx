import { fireEvent, screen, waitFor } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import * as browser from '@/lib/api/browser';
import type { Reissue } from '@/lib/api/credentials';
import { ApiError } from '@/lib/api/errors';
import type { Voter } from '@/lib/api/voters';
import { LostSlipDialog } from './LostSlipDialog';

vi.mock('@/lib/api/browser', () => ({
  fetchVoters: vi.fn(),
  reissueCredential: vi.fn(),
  downloadGeneratedFile: vi.fn(),
}));

const api = vi.mocked(browser);
const ELECTION = '00000000-0000-4000-8000-000000000001';
const GROUP = { id: '10000000-0000-4000-8000-000000000004', name: '4e année' };

function voter(name: string, n: number, group: Voter['group']): Voter {
  return {
    id: `20000000-0000-4000-8000-00000000000${n}`,
    full_name: name,
    group,
    identifier: `E-${n}`,
    email: null,
    phone: null,
    has_code: false,
    email_status: null,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
  };
}

function reissue(count: number): Reissue {
  return {
    voter: {
      id: '20000000-0000-4000-8000-000000000001',
      full_name: 'Rose-Marie Désir',
      group: GROUP,
    },
    code: 'K7MP4-QX9RT',
    reissue_count: count,
    email: null,
    file: {
      id: '50000000-0000-4000-8000-000000000009',
      kind: 'slips_pdf',
      group: GROUP,
      part: 1,
      parts: 1,
      voter_count: 1,
      status: 'ready',
      failure: null,
      size_bytes: 9000,
      expires_at: '2026-10-20T00:00:00Z',
      created_at: '2026-10-10T19:12:05Z',
    },
  };
}

function show(onClose = vi.fn(), onLocked = vi.fn()) {
  renderIn('fr', <LostSlipDialog election={ELECTION} onLocked={onLocked} onClose={onClose} />);

  return { onClose, onLocked };
}

async function search(text: string) {
  fireEvent.change(screen.getByTestId('lost-search'), { target: { value: text } });
  await screen.findByTestId('lost-voter-1');
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
  api.fetchVoters.mockResolvedValue({
    items: [voter('Rose-Marie Désir', 1, GROUP), voter('Rosa Pierre', 2, null)],
    total: 2,
    page: 1,
  });
});

describe('LostSlipDialog', () => {
  it('searches by name with the election, and numbers the results from 1', async () => {
    show();
    await search('Ros');

    expect(api.fetchVoters).toHaveBeenCalledWith(ELECTION, {
      page: 1,
      q: 'Ros',
      group: '',
      credential: '',
      email: '',
    });
    expect(screen.getByTestId('lost-voter-1')).toHaveTextContent('Rose-Marie Désir');
    expect(screen.getByTestId('lost-voter-2')).toHaveTextContent('Sans groupe');
    expect(screen.getByTestId('lost-confirm')).toBeDisabled();
  });

  it('shows the code once, exactly as sent, and clears it on close', async () => {
    api.reissueCredential.mockResolvedValue(reissue(1));
    const { onClose } = show();
    await search('Rose');

    fireEvent.click(screen.getByTestId('lost-voter-1'));
    fireEvent.click(screen.getByTestId('lost-confirm'));

    await waitFor(() => expect(screen.getByTestId('lost-code')).toBeInTheDocument());
    expect(api.reissueCredential).toHaveBeenCalledWith('20000000-0000-4000-8000-000000000001');
    expect(screen.getByTestId('lost-code').textContent).toBe('K7MP4-QX9RT');
    expect(screen.getByTestId('lost-dialog')).toHaveTextContent('une seule fois');
    expect(screen.getByTestId('lost-dialog')).toHaveTextContent('ne fonctionne plus');
    expect(document.body.innerHTML.match(/K7MP4-QX9RT/g)).toHaveLength(1);
    expect(document.querySelector('[data-code]')).toBeNull();
    expect(localStorage.length).toBe(0);

    fireEvent.click(screen.getByTestId('lost-close'));

    expect(onClose).toHaveBeenCalledWith(true);
  });

  it('says it is a first code when the voter had none', async () => {
    api.reissueCredential.mockResolvedValue(reissue(0));
    show();
    await search('Rose');

    fireEvent.click(screen.getByTestId('lost-voter-1'));
    fireEvent.click(screen.getByTestId('lost-confirm'));

    await waitFor(() => expect(screen.getByTestId('lost-code')).toBeInTheDocument());
    expect(screen.getByTestId('lost-dialog')).toHaveTextContent('premier code');
  });

  it('downloads the one-slip file', async () => {
    api.reissueCredential.mockResolvedValue(reissue(1));
    api.downloadGeneratedFile.mockResolvedValue({
      blob: new Blob(['%PDF-']),
      name: 'coupons-4e-annee.pdf',
    });
    URL.createObjectURL = vi.fn(() => 'blob:x');
    URL.revokeObjectURL = vi.fn();
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(() => undefined);
    show();
    await search('Rose');
    fireEvent.click(screen.getByTestId('lost-voter-1'));
    fireEvent.click(screen.getByTestId('lost-confirm'));
    await screen.findByTestId('lost-code');

    fireEvent.click(screen.getByTestId('lost-download'));

    await waitFor(() => expect(click).toHaveBeenCalled());
    expect(api.downloadGeneratedFile).toHaveBeenCalledWith('50000000-0000-4000-8000-000000000009');
    click.mockRestore();
  });

  it('tells the page when the election turned out to be locked', async () => {
    api.reissueCredential.mockRejectedValue(new ApiError(409, 'election_codes_locked'));
    const { onLocked } = show();
    await search('Rose');

    fireEvent.click(screen.getByTestId('lost-voter-1'));
    fireEvent.click(screen.getByTestId('lost-confirm'));

    await waitFor(() => expect(onLocked).toHaveBeenCalled());
    expect(screen.queryByTestId('lost-code')).not.toBeInTheDocument();
  });

  it('says another refusal in words and keeps the choice', async () => {
    api.reissueCredential.mockRejectedValue(new ApiError(409, 'voter_has_voted'));
    show();
    await search('Rose');

    fireEvent.click(screen.getByTestId('lost-voter-1'));
    fireEvent.click(screen.getByTestId('lost-confirm'));

    await waitFor(() => expect(screen.getByTestId('lost-error')).toHaveTextContent('déjà voté'));
    expect(screen.getByTestId('lost-confirm')).not.toBeDisabled();
  });
});
