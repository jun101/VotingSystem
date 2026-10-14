import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { VoterBallots } from '@/lib/api/vote';
import { Review } from './Review';

const push = vi.fn();
const replace = vi.fn();
const submitVote = vi.fn();

vi.mock('next/navigation', () => ({ useRouter: () => ({ push, replace }) }));
vi.mock('@/lib/api/vote', async (original) => ({
  ...(await original<typeof import('@/lib/api/vote')>()),
  submitVote: (...args: unknown[]) => submitVote(...args),
}));

const ELECTION = '00000000-0000-4000-8000-0000000000e1';
const B1 = '00000000-0000-4000-8000-0000000000b1';
const B2 = '00000000-0000-4000-8000-0000000000b2';
const C1 = '00000000-0000-4000-8000-0000000000c1';
const C2 = '00000000-0000-4000-8000-0000000000c2';

const ballots = {
  voter: { full_name: 'Gaëlle Saint-Fleur', group: '3e année' },
  ballots: [
    {
      id: B1,
      title: 'Président(e)',
      seats: 1,
      allow_blank: true,
      scope: 'all',
      group_names: [],
      description: null,
      candidates: [
        {
          id: C1,
          first_name: 'Nadège',
          last_name: 'Pierre-Louis',
          sex: 'female',
          slogan: null,
          photo: null,
          party: null,
        },
        {
          id: C2,
          first_name: 'Jean-Marc',
          last_name: 'Désir',
          sex: 'male',
          slogan: null,
          photo: null,
          party: null,
        },
      ],
    },
    {
      id: B2,
      title: 'Secrétaire',
      seats: 1,
      allow_blank: true,
      scope: 'all',
      group_names: [],
      description: null,
      candidates: [
        {
          id: C2,
          first_name: 'Jean-Marc',
          last_name: 'Désir',
          sex: 'male',
          slogan: null,
          photo: null,
          party: null,
        },
      ],
    },
  ],
} as unknown as VoterBallots;

let choices: Record<string, string[]> = {};

vi.mock('./VoteProvider', () => ({
  useVote: () => ({
    election: ELECTION,
    choices,
    setBallots: vi.fn(),
    setPhase: vi.fn(),
    setVotedAt: vi.fn(),
    leave: vi.fn(),
    forget: vi.fn(),
    info: {
      institution: 'FIC',
      title: 'Conseil',
      ends_at: '2026-10-16T19:00:00Z',
      timezone: 'America/Port-au-Prince',
    },
  }),
  useVoterBallots: () => ({ ballots, failure: null, retry: vi.fn() }),
}));

beforeEach(() => {
  push.mockReset();
  replace.mockReset();
  submitVote.mockReset();
  choices = { [B1]: [C1], [B2]: [] };
});

describe('Review', () => {
  it('lists every post as a row, with the chosen candidate or the blank vote', () => {
    renderIn('fr', <Review />);

    const first = screen.getByTestId('vote-review-ballot-1');
    const second = screen.getByTestId('vote-review-ballot-2');

    expect(within(first).getByText('Nadège Pierre-Louis')).toBeInTheDocument();
    expect(within(second).getByText('Vote blanc')).toBeInTheDocument();
  });

  it('says every post is completed', () => {
    renderIn('fr', <Review />);

    expect(screen.getByText('2 postes sur 2 complétés')).toBeInTheDocument();
  });

  it('links each row back to its ballot to change the choice', () => {
    renderIn('fr', <Review />);

    expect(screen.getByTestId('vote-review-edit-2')).toHaveAttribute(
      'href',
      `/vote/${ELECTION}/ballot/2`,
    );
  });

  it('confirms once from the card button and sends every ballot with its choice', async () => {
    submitVote.mockResolvedValue({ voted_at: '2026-10-13T12:00:00Z' });
    renderIn('fr', <Review />);

    const buttons = screen.getAllByRole('button', { name: /Confirmer mon vote/ });

    expect(buttons).toHaveLength(2);

    await userEvent.setup().click(buttons[0]!);

    expect(submitVote).toHaveBeenCalledTimes(1);
    expect(submitVote).toHaveBeenCalledWith(ELECTION, [
      { ballot: B1, candidates: [C1] },
      { ballot: B2, candidates: [] },
    ]);
  });

  it('keeps the bar button the end-to-end tests use', () => {
    renderIn('fr', <Review />);

    expect(screen.getByTestId('vote-confirm')).toBeInTheDocument();
  });

  it('shows the three reminders of the rail', () => {
    renderIn('fr', <Review />);

    expect(screen.getByText('Avant de confirmer')).toBeInTheDocument();
    expect(screen.getByText(/Il est définitif/)).toBeInTheDocument();
  });
});
