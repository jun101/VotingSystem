import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election } from '@/lib/api/elections';
import { CopyVotersDialog } from './CopyVotersDialog';

const fetchElectionsPage = vi.fn();
const fetchGroups = vi.fn();
const copyVoters = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  fetchElectionsPage: (...args: unknown[]) => fetchElectionsPage(...args),
  fetchGroups: (...args: unknown[]) => fetchGroups(...args),
  copyVoters: (...args: unknown[]) => copyVoters(...args),
}));

function election(n: number, status: Election['status'], title: string): Election {
  return {
    id: `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
    title,
    description: null,
    status,
    starts_at: '2026-10-12T12:00:00Z',
    ends_at: '2026-10-16T19:00:00Z',
    timezone: 'America/Port-au-Prince',
    language: 'fr',
    candidate_order: 'manual',
    results_display: 'full',
    cover: null,
    ballots_count: 0,
    voters_count: 0,
    created_at: '2026-10-08T15:20:00Z',
  };
}

const TARGET = '00000000-0000-4000-8000-000000000099';
const GROUP = '00000000-0000-4000-8000-0000000000aa';

beforeAll(() => {
  // jsdom has no modal dialog.
  HTMLDialogElement.prototype.showModal = function showModal() {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function close() {
    this.removeAttribute('open');
  };
});

beforeEach(() => {
  fetchElectionsPage.mockReset();
  fetchGroups.mockReset();
  copyVoters.mockReset();
  fetchElectionsPage.mockImplementation(async (filters: { status?: string }) =>
    filters.status === 'archived'
      ? { items: [election(2, 'archived', 'Archive 2024')], total: 1 }
      : { items: [election(1, 'closed', 'Conseil 2025')], total: 1 },
  );
  fetchGroups.mockResolvedValue({
    items: [{ id: GROUP, name: '4e année', voters_count: 12 }],
    ungrouped: 3,
  });
  copyVoters.mockResolvedValue({ copied: 15, skipped: 0, groups_created: 1 });
});

describe('CopyVotersDialog', () => {
  it('offers the archived elections as sources too', async () => {
    renderIn(
      'fr',
      <CopyVotersDialog election={TARGET} onDone={vi.fn()} onLocked={vi.fn()} onCancel={vi.fn()} />,
    );

    await screen.findByRole('option', { name: 'Archive 2024' });

    expect(screen.getByRole('option', { name: 'Conseil 2025' })).toBeInTheDocument();
    expect(fetchElectionsPage).toHaveBeenCalledWith({ status: 'archived' }, 1);
  });

  it('shows the rename inputs for every group and sends the renames with the source alone', async () => {
    const user = userEvent.setup();

    renderIn(
      'fr',
      <CopyVotersDialog election={TARGET} onDone={vi.fn()} onLocked={vi.fn()} onCancel={vi.fn()} />,
    );

    await screen.findByRole('option', { name: 'Archive 2024' });
    await user.selectOptions(screen.getByTestId('copy-source'), 'Archive 2024');

    const rename = await screen.findByTestId('copy-rename-1');

    await user.type(rename, '5e année');
    await user.click(screen.getByTestId('copy-submit'));

    await waitFor(() => expect(copyVoters).toHaveBeenCalled());

    expect(copyVoters).toHaveBeenCalledWith(TARGET, {
      source: '00000000-0000-4000-8000-000000000002',
      rename: { [GROUP]: '5e année' },
    });
  });
});
