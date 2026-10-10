import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Ballot } from '@/lib/api/ballots';
import type { Election } from '@/lib/api/elections';
import { ApiError } from '@/lib/api/errors';
import { BallotsPage } from './BallotsPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const reorderBallots = vi.fn();
const createBallot = vi.fn();
const fetchBallots = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  reorderBallots: (...args: unknown[]) => reorderBallots(...args),
  createBallot: (...args: unknown[]) => createBallot(...args),
  fetchBallots: (...args: unknown[]) => fetchBallots(...args),
  updateBallot: vi.fn(),
  deleteBallot: vi.fn(),
}));

const election: Election = {
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
  ballots_count: 3,
  voters_count: 0,
  created_at: '2026-10-08T15:20:00Z',
};

function ballot(title: string, position: number, extra: Partial<Ballot> = {}): Ballot {
  return {
    id: `10000000-0000-4000-8000-00000000000${position}`,
    title,
    description: null,
    position,
    seats: 1,
    allow_blank: true,
    candidates_count: 0,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
    ...extra,
  };
}

const three = [ballot('A', 1), ballot('B', 2, { seats: 3, allow_blank: false }), ballot('C', 3)];
const titles = () => screen.getAllByTestId(/^ballot-title-/).map((node) => node.textContent);

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
  vi.clearAllMocks();
});

describe('BallotsPage', () => {
  it('shows the cards in order with seats, blank vote and count (fr)', () => {
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    expect(titles()).toEqual(['A', 'B', 'C']);
    expect(screen.getByTestId('ballots-count')).toHaveTextContent('3 postes');
    expect(screen.getByTestId('ballot-seats-1')).toHaveTextContent('1 siège');
    expect(screen.getByTestId('ballot-seats-2')).toHaveTextContent('3 sièges');
    expect(screen.getByTestId('ballot-blank-1')).toHaveTextContent('Vote blanc');
    expect(screen.getByTestId('ballot-blank-2')).toHaveTextContent('Sans vote blanc');
    expect(screen.getByTestId('ballot-new-tile')).toBeInTheDocument();
  });

  it('shows the same page in English', () => {
    renderIn('en', <BallotsPage election={election} initial={three.slice(0, 1)} />);

    expect(screen.getByTestId('ballots-count')).toHaveTextContent('1 position');
    expect(screen.getByTestId('ballots-title')).toHaveTextContent('Positions and candidates');
  });

  it('disables the first up and the last down button', () => {
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    expect(screen.getByTestId('ballot-up-1')).toBeDisabled();
    expect(screen.getByTestId('ballot-down-3')).toBeDisabled();
    expect(screen.getByTestId('ballot-down-1')).toBeEnabled();
    expect(screen.getByTestId('ballot-up-3')).toBeEnabled();
  });

  it('reorders at once and saves the whole order with one call', async () => {
    reorderBallots.mockResolvedValue([three[1], three[0], three[2]]);
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    expect(titles()).toEqual(['B', 'A', 'C']);
    await waitFor(() => expect(reorderBallots).toHaveBeenCalledTimes(1));
    expect(reorderBallots).toHaveBeenCalledWith(election.id, [
      three[1]!.id,
      three[0]!.id,
      three[2]!.id,
    ]);
  });

  it('brings the saved order back, with a notice, when the save fails', async () => {
    reorderBallots.mockRejectedValue(new ApiError(500, 'server_error'));
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    expect(await screen.findByTestId('ballots-notice')).toHaveTextContent(
      'L’ordre n’a pas pu être enregistré',
    );
    expect(titles()).toEqual(['A', 'B', 'C']);
  });

  it('shows the "no longer editable" notice and reads the list again on a 409', async () => {
    reorderBallots.mockRejectedValue(new ApiError(409, 'election_not_editable'));
    fetchBallots.mockResolvedValue([three[0]]);
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    expect(await screen.findByTestId('ballots-notice')).toHaveTextContent(
      'Cette élection ne peut plus être modifiée',
    );
    await waitFor(() => expect(titles()).toEqual(['A']));
    expect(fetchBallots).toHaveBeenCalledWith(election.id);
  });

  it('keeps the form open with a message beside the title when it is empty', async () => {
    renderIn('fr', <BallotsPage election={election} initial={[]} />);

    await userEvent.click(screen.getByTestId('ballots-add'));
    await userEvent.click(screen.getByTestId('ballot-form-save'));

    expect(screen.getByTestId('ballot-form-error-title')).toBeInTheDocument();
    expect(screen.getByTestId('ballot-form')).toBeInTheDocument();
    expect(createBallot).not.toHaveBeenCalled();
  });

  it('adds a ballot at the end with the values of the form', async () => {
    createBallot.mockResolvedValue(ballot('Délégués', 1, { seats: 2 }));
    renderIn('fr', <BallotsPage election={election} initial={[]} />);

    await userEvent.click(screen.getByTestId('ballots-add'));
    await userEvent.type(screen.getByTestId('ballot-form-title'), 'Délégués');
    await userEvent.clear(screen.getByTestId('ballot-form-seats'));
    await userEvent.type(screen.getByTestId('ballot-form-seats'), '2');
    await userEvent.click(screen.getByTestId('ballot-form-save'));

    expect(createBallot).toHaveBeenCalledWith(election.id, {
      title: 'Délégués',
      description: null,
      seats: 2,
      allow_blank: true,
    });
    await waitFor(() => expect(titles()).toEqual(['Délégués']));
    expect(screen.queryByTestId('ballot-form')).not.toBeInTheDocument();
  });

  it('names the ballot in the delete confirmation', async () => {
    renderIn('fr', <BallotsPage election={election} initial={three} />);

    await userEvent.click(screen.getByTestId('ballot-delete-2'));

    expect(
      within(screen.getByTestId('ballot-delete-dialog')).getByText(/«\sB\s»/),
    ).toBeInTheDocument();
  });

  it('only shows the ballots of an election that is not a draft', () => {
    renderIn('fr', <BallotsPage election={{ ...election, status: 'scheduled' }} initial={three} />);

    expect(titles()).toEqual(['A', 'B', 'C']);
    expect(screen.queryByTestId('ballots-add')).not.toBeInTheDocument();
    expect(screen.queryByTestId('ballot-up-2')).not.toBeInTheDocument();
    expect(screen.queryByTestId('ballot-edit-1')).not.toBeInTheDocument();
    expect(screen.getByTestId('ballots-locked')).toBeInTheDocument();
  });
});
