import { screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import type { GroupList, Voter, VoterList } from '@/lib/api/voters';
import { VotersPage } from './VotersPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/lib/api/browser', () => ({
  fetchVoters: vi.fn(),
  fetchGroups: vi.fn(),
  createVoter: vi.fn(),
  updateVoter: vi.fn(),
  deleteVoter: vi.fn(),
  createGroup: vi.fn(),
  renameGroup: vi.fn(),
  deleteGroup: vi.fn(),
  mergeGroup: vi.fn(),
}));

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
    voters_count: 2,
    created_at: '2026-10-08T15:20:00Z',
  };
}

function voter(name: string, n: number, group: Voter['group']): Voter {
  return {
    id: `20000000-0000-4000-8000-00000000000${n}`,
    full_name: name,
    group,
    identifier: `E-${n}`,
    email: null,
    phone: null,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
  };
}

const GROUP = { id: '10000000-0000-4000-8000-000000000001', name: '4e année' };

const list: VoterList = {
  items: [voter('Rose-Marie Désir', 1, GROUP), voter('Jean Pierre', 2, null)],
  total: 2,
  page: 1,
};

const groups: GroupList = {
  items: [
    {
      ...GROUP,
      voters_count: 1,
      created_at: '2026-10-10T14:00:00Z',
      updated_at: '2026-10-10T14:00:00Z',
    },
  ],
  votersTotal: 2,
  ungrouped: 1,
};

function show(status: ElectionStatus, locale: 'fr' | 'en' = 'fr') {
  return renderIn(
    locale,
    <VotersPage
      election={election(status)}
      initialFilters={{ page: 1, q: '', group: '' }}
      initial={list}
      initialGroups={groups}
    />,
  );
}

beforeAll(() => {
  // jsdom has no modal dialog.
  HTMLDialogElement.prototype.showModal = function showModal() {
    this.setAttribute('open', '');
  };
  HTMLDialogElement.prototype.close = function close() {
    this.removeAttribute('open');
  };
});

describe('VotersPage', () => {
  it('shows the cards, the chips and the groups card (fr)', () => {
    show('draft');

    expect(screen.getByTestId('voter-name-1')).toHaveTextContent('Rose-Marie Désir');
    expect(screen.getByTestId('voter-group-1')).toHaveTextContent('4e année');
    expect(screen.getByTestId('voter-group-2')).toHaveTextContent('Sans groupe');
    expect(screen.getByTestId('voters-count')).toHaveTextContent('2 électeurs');
    expect(screen.getByTestId('ungrouped-count')).toHaveTextContent('1 sans groupe');
    expect(screen.getByTestId('voters-range')).toHaveTextContent('1 à 2 sur 2 électeurs');
    expect(screen.getByTestId('voters-filter-1')).toHaveTextContent('4e année');
    expect(screen.getByTestId('voters-filter-all')).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('group-name-1')).toHaveTextContent('4e année');
  });

  it('shows the same page in English', () => {
    show('draft', 'en');

    expect(screen.getByTestId('voters-count')).toHaveTextContent('2 voters');
    expect(screen.getByTestId('voter-group-2')).toHaveTextContent('No group');
    expect(screen.getByTestId('voter-add')).toHaveTextContent('Add a voter');
  });

  it.each<ElectionStatus>(['draft', 'scheduled'])(
    'offers every write button in a %s election',
    (status) => {
      show(status);

      expect(screen.getByTestId('voter-add')).toBeInTheDocument();
      expect(screen.getByTestId('voter-edit-1')).toBeInTheDocument();
      expect(screen.getByTestId('voter-delete-1')).toBeInTheDocument();
      expect(screen.getByTestId('group-new')).toBeInTheDocument();
      expect(screen.getByTestId('group-rename-1')).toBeInTheDocument();
      expect(screen.getByTestId('group-merge-1')).toBeInTheDocument();
      expect(screen.getByTestId('group-delete-1')).toBeInTheDocument();
      expect(screen.queryByTestId('voters-locked-notice')).not.toBeInTheDocument();
    },
  );

  it('lets an open election add and edit voters but not delete them or manage groups', () => {
    show('open');

    expect(screen.getByTestId('voter-add')).toBeInTheDocument();
    expect(screen.getByTestId('voter-edit-1')).toBeInTheDocument();
    expect(screen.queryByTestId('voter-delete-1')).not.toBeInTheDocument();
    expect(screen.queryByTestId('group-new')).not.toBeInTheDocument();
    expect(screen.queryByTestId('group-rename-1')).not.toBeInTheDocument();
    expect(screen.queryByTestId('group-merge-1')).not.toBeInTheDocument();
    expect(screen.queryByTestId('group-delete-1')).not.toBeInTheDocument();
    expect(screen.queryByTestId('voters-locked-notice')).not.toBeInTheDocument();
  });

  it.each<ElectionStatus>(['closed', 'published', 'archived'])(
    'shows no write button and the locked notice in a %s election',
    (status) => {
      show(status);

      // Reading stays: the cards and the groups are there.
      expect(screen.getByTestId('voter-card-1')).toBeInTheDocument();
      expect(screen.getByTestId('group-row-1')).toBeInTheDocument();
      expect(screen.getByTestId('voters-locked-notice')).toBeInTheDocument();

      for (const id of [
        'voter-add',
        'voter-edit-1',
        'voter-delete-1',
        'group-new',
        'group-rename-1',
        'group-merge-1',
        'group-delete-1',
      ]) {
        expect(screen.queryByTestId(id), id).not.toBeInTheDocument();
      }

      expect(screen.queryByTestId('voter-modal')).not.toBeInTheDocument();
    },
  );

  it('disables the delete button of a group that has voters', () => {
    show('draft');

    expect(screen.getByTestId('group-delete-1')).toBeDisabled();
  });

  it('shows no add button on the empty state of a locked election', () => {
    renderIn(
      'fr',
      <VotersPage
        election={election('closed')}
        initialFilters={{ page: 1, q: '', group: '' }}
        initial={{ items: [], total: 0, page: 1 }}
        initialGroups={{ items: [], votersTotal: 0, ungrouped: 0 }}
      />,
    );

    expect(screen.getByTestId('voters-empty')).toBeInTheDocument();
    expect(screen.queryByTestId('voters-empty-add')).not.toBeInTheDocument();
    expect(screen.queryByTestId('voter-add')).not.toBeInTheDocument();
  });
});
