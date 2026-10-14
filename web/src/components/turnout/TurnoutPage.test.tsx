import { cleanup, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import * as browser from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import type { Turnout } from '@/lib/api/turnout';
import type { GroupList, Voter, VoterList } from '@/lib/api/voters';
import { TurnoutPage } from './TurnoutPage';

vi.mock('@/lib/api/browser', () => ({
  fetchVoters: vi.fn(),
  fetchTurnout: vi.fn(),
  downloadVoterExport: vi.fn(),
}));

vi.mock('@/components/codes/saveFile', () => ({ saveBlob: vi.fn() }));

const api = vi.mocked(browser);

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
    voters_count: 6,
    created_at: '2026-10-08T15:20:00Z',
  };
}

function voter(name: string, n: number): Voter {
  return {
    id: `20000000-0000-4000-8000-00000000000${n}`,
    full_name: name,
    group: null,
    identifier: null,
    email: null,
    phone: null,
    has_code: true,
    email_status: null,
    has_voted: false,
    voted_at: null,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
  };
}

const TURNOUT: Turnout = {
  eligible: 6,
  voted: 2,
  percentage: 33.3,
  by_group: [
    {
      group: { id: '10000000-0000-4000-8000-000000000001', name: '4e année' },
      voters: 3,
      voted: 1,
    },
    { group: null, voters: 1, voted: 0 },
  ],
  bucket: 'hour',
  over_time: [
    { at: '2026-10-12T12:00:00Z', voted: 1, total: 1 },
    { at: '2026-10-12T13:00:00Z', voted: 1, total: 2 },
  ],
};

const VOTERS: VoterList = {
  items: [voter('Bob Louis', 1), voter('Cléo Pierre', 2)],
  total: 2,
  page: 1,
};

const GROUPS: GroupList = {
  items: [
    {
      id: '10000000-0000-4000-8000-000000000001',
      name: '4e année',
      voters_count: 3,
      ballots_count: 0,
      created_at: '2026-10-10T14:00:00Z',
      updated_at: '2026-10-10T14:00:00Z',
    },
  ],
  votersTotal: 6,
  ungrouped: 1,
};

function show(status: ElectionStatus, turnout: Turnout = TURNOUT, voters: VoterList = VOTERS) {
  return renderIn(
    'fr',
    <TurnoutPage
      election={election(status)}
      initialTurnout={turnout}
      initialVoters={voters}
      groups={GROUPS}
    />,
  );
}

beforeEach(() => {
  api.fetchVoters.mockResolvedValue(VOTERS);
  api.fetchTurnout.mockResolvedValue(TURNOUT);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('TurnoutPage', () => {
  it('shows the figures, the groups, the chart and the list of who has not voted', () => {
    show('open');

    expect(screen.getByTestId('turnout-eligible')).toHaveTextContent('6');
    expect(screen.getByTestId('turnout-voted')).toHaveTextContent('2');
    expect(screen.getByTestId('turnout-percentage')).toHaveTextContent('33');
    expect(screen.getByTestId('turnout-group-1')).toHaveTextContent('4e année');
    expect(screen.getByTestId('turnout-group-1')).toHaveTextContent('1 / 3');
    expect(screen.getByTestId('turnout-group-2')).toHaveTextContent('Sans groupe');
    expect(screen.getByTestId('turnout-group-2')).toHaveTextContent('0 / 1');
    expect(screen.getByTestId('nonvoter-name-1')).toHaveTextContent('Bob Louis');

    const chart = screen.getByTestId('turnout-chart-time');

    expect(chart.tagName.toLowerCase()).toBe('svg');
    expect(chart).toHaveAttribute('role', 'img');
    expect(chart.getAttribute('aria-label')).toContain('2 électeurs ont voté');
  });

  it('says so, in words, for an empty series', () => {
    show('scheduled', { ...TURNOUT, voted: 0, percentage: 0, over_time: [] });

    expect(screen.getByTestId('turnout-chart-time').getAttribute('aria-label')).toContain(
      'personne n’a encore voté',
    );
  });

  it('shows a clear state when everyone has voted', () => {
    show('open', { ...TURNOUT, voted: 6, percentage: 100 }, { items: [], total: 0, page: 1 });

    expect(screen.getByTestId('nonvoters-all-voted')).toBeInTheDocument();
    expect(screen.queryByTestId('nonvoters-list')).not.toBeInTheDocument();
  });

  it('filters the list by group and sends the same filters to the export', async () => {
    const user = userEvent.setup();

    api.downloadVoterExport.mockResolvedValue({
      blob: new Blob(['x']),
      name: 'electeurs-conseil.csv',
    });
    show('open');

    await user.selectOptions(screen.getByTestId('nonvoters-group'), 'none');

    await waitFor(() =>
      expect(api.fetchVoters).toHaveBeenLastCalledWith(
        '00000000-0000-4000-8000-000000000001',
        expect.objectContaining({ group: 'none', voted: 'no', page: 1 }),
      ),
    );

    await user.click(screen.getByTestId('nonvoters-export-csv'));

    await waitFor(() =>
      expect(api.downloadVoterExport).toHaveBeenCalledWith('00000000-0000-4000-8000-000000000001', {
        format: 'csv',
        voted: 'no',
        group: 'none',
        q: '',
      }),
    );
  });

  it('does not poll a closed election', async () => {
    vi.useFakeTimers();
    show('closed');
    await vi.advanceTimersByTimeAsync(45_000);
    vi.useRealTimers();

    expect(api.fetchTurnout).not.toHaveBeenCalled();
  });

  it('polls an open election every 15 seconds', async () => {
    vi.useFakeTimers();
    show('open');
    await vi.advanceTimersByTimeAsync(15_000);
    vi.useRealTimers();

    expect(api.fetchTurnout).toHaveBeenCalledTimes(1);
    expect(api.fetchVoters).toHaveBeenCalledTimes(1);
  });
});
