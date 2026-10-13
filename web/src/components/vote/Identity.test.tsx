import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { VoterBallots } from '@/lib/api/vote';
import { Identity } from './Identity';

const push = vi.fn();
const signOut = vi.fn();
const fetchBallots = vi.fn();

vi.mock('next/navigation', () => ({ useRouter: () => ({ push, replace: vi.fn() }) }));
vi.mock('@/lib/api/vote', async (original) => ({
  ...(await original<typeof import('@/lib/api/vote')>()),
  fetchBallots: (...args: unknown[]) => fetchBallots(...args),
}));

const ELECTION = '00000000-0000-4000-8000-0000000000e1';

function ballot(n: number, scope = 'all', groups: string[] = []) {
  return {
    id: `00000000-0000-4000-8000-00000000000${n}`,
    title: `Poste ${n}`,
    seats: n,
    allow_blank: false,
    scope,
    group_names: groups,
    description: null,
    candidates: [],
  };
}

let current: VoterBallots;

vi.mock('./VoteProvider', () => ({
  useVote: () => ({
    election: ELECTION,
    signOut,
    leave: vi.fn(),
    setPhase: vi.fn(),
    setBallots: vi.fn(),
    info: {
      institution: 'Collège Les Flamboyants',
      title: 'Conseil des élèves',
      ends_at: '2026-10-16T19:00:00Z',
      timezone: 'America/Port-au-Prince',
    },
  }),
  useVoterBallots: () => ({ ballots: current, failure: null, retry: vi.fn() }),
}));

beforeEach(() => {
  push.mockReset();
  signOut.mockReset();
  fetchBallots.mockReset();
  fetchBallots.mockResolvedValue(current);
  current = {
    voter: { full_name: 'Gaëlle Saint-Fleur', group: '3e année' },
    ballots: [ballot(1), ballot(2, 'group', ['3e année'])],
  } as unknown as VoterBallots;
  fetchBallots.mockResolvedValue(current);
});

describe('Identity', () => {
  it('shows the voter in the card and the election in the header', () => {
    renderIn('fr', <Identity />);

    expect(screen.getByTestId('vote-identity-name')).toHaveTextContent('Gaëlle Saint-Fleur');
    expect(screen.getByTestId('vote-identity-group')).toHaveTextContent('3e année');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Conseil des élèves');
    expect(screen.getByText('Étape 2 sur 3')).toBeInTheDocument();
    expect(screen.getByText('Vote secret')).toBeInTheDocument();
  });

  it('shows the initials of the voter in the avatar', () => {
    renderIn('fr', <Identity />);

    expect(screen.getByText('GS')).toBeInTheDocument();
  });

  it('lists the posts of the voter with their seats and who they are for', () => {
    renderIn('fr', <Identity />);

    const second = screen.getByTestId('vote-identity-ballot-2');

    expect(within(second).getByText('Poste 2')).toBeInTheDocument();
    expect(within(second).getByText('2 sièges')).toBeInTheDocument();
    expect(within(second).getByText('Réservé à : 3e année')).toBeInTheDocument();
    expect(screen.getByText('postes à voter')).toBeInTheDocument();
  });

  it('goes to the first ballot on "yes, it is me"', async () => {
    renderIn('fr', <Identity />);

    await userEvent.setup().click(screen.getByTestId('vote-identity-yes'));

    expect(push).toHaveBeenCalledWith(`/vote/${ELECTION}/ballot/1`);
  });

  it('ends the session on "it is not me"', async () => {
    renderIn('fr', <Identity />);

    await userEvent.setup().click(screen.getByTestId('vote-identity-no'));

    expect(signOut).toHaveBeenCalledWith(null);
  });

  it('offers no way forward when no post concerns the voter', () => {
    current = { ...current, ballots: [] } as VoterBallots;
    renderIn('fr', <Identity />);

    expect(screen.queryByTestId('vote-identity-yes')).toBeNull();
    expect(screen.getByText('Aucun poste ne vous concerne')).toBeInTheDocument();
  });
});
