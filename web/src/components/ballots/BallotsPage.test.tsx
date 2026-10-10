import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Ballot } from '@/lib/api/ballots';
import type { Candidate } from '@/lib/api/candidates';
import type { Election } from '@/lib/api/elections';
import type { Party } from '@/lib/api/parties';
import { ApiError } from '@/lib/api/errors';
import { BallotsPage } from './BallotsPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const reorderBallots = vi.fn();
const createBallot = vi.fn();
const fetchBallots = vi.fn();
const fetchParties = vi.fn();
const createParty = vi.fn();
const deleteParty = vi.fn();
const createCandidate = vi.fn();
const deleteCandidate = vi.fn();
const reorderCandidates = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  reorderBallots: (...args: unknown[]) => reorderBallots(...args),
  createBallot: (...args: unknown[]) => createBallot(...args),
  fetchBallots: (...args: unknown[]) => fetchBallots(...args),
  fetchParties: (...args: unknown[]) => fetchParties(...args),
  createParty: (...args: unknown[]) => createParty(...args),
  updateParty: vi.fn(),
  uploadPartyLogo: vi.fn(),
  removePartyLogo: vi.fn(),
  deleteParty: (...args: unknown[]) => deleteParty(...args),
  updateBallot: vi.fn(),
  deleteBallot: vi.fn(),
  createCandidate: (...args: unknown[]) => createCandidate(...args),
  updateCandidate: vi.fn(),
  uploadCandidatePhoto: vi.fn(),
  removeCandidatePhoto: vi.fn(),
  deleteCandidate: (...args: unknown[]) => deleteCandidate(...args),
  reorderCandidates: (...args: unknown[]) => reorderCandidates(...args),
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
    candidates: [],
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
    ...extra,
  };
}

function candidate(name: string, ballotPosition: number, party: string | null = null): Candidate {
  return {
    id: `30000000-0000-4000-8000-0000000000${name.charCodeAt(0)}`,
    ballot: `10000000-0000-4000-8000-00000000000${ballotPosition}`,
    party,
    first_name: name,
    last_name: 'Nom',
    sex: name === 'a' ? 'male' : 'female',
    slogan: null,
    biography: null,
    photo: null,
    position: 1,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
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
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    expect(titles()).toEqual(['A', 'B', 'C']);
    expect(screen.getByTestId('ballots-count')).toHaveTextContent('3 postes');
    expect(screen.getByTestId('ballot-seats-1')).toHaveTextContent('1 siège');
    expect(screen.getByTestId('ballot-seats-2')).toHaveTextContent('3 sièges');
    expect(screen.getByTestId('ballot-blank-1')).toHaveTextContent('Vote blanc');
    expect(screen.getByTestId('ballot-blank-2')).toHaveTextContent('Sans vote blanc');
    expect(screen.getByTestId('ballot-new-tile')).toBeInTheDocument();
  });

  it('shows the same page in English', () => {
    renderIn(
      'en',
      <BallotsPage election={election} initial={three.slice(0, 1)} initialParties={[]} />,
    );

    expect(screen.getByTestId('ballots-count')).toHaveTextContent('1 position');
    expect(screen.getByTestId('ballots-title')).toHaveTextContent('Positions and candidates');
  });

  it('disables the first up and the last down button', () => {
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    expect(screen.getByTestId('ballot-up-1')).toBeDisabled();
    expect(screen.getByTestId('ballot-down-3')).toBeDisabled();
    expect(screen.getByTestId('ballot-down-1')).toBeEnabled();
    expect(screen.getByTestId('ballot-up-3')).toBeEnabled();
  });

  it('reorders at once and saves the whole order with one call', async () => {
    reorderBallots.mockResolvedValue([three[1], three[0], three[2]]);
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

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
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    expect(await screen.findByTestId('ballots-notice')).toHaveTextContent(
      'L’ordre n’a pas pu être enregistré',
    );
    expect(titles()).toEqual(['A', 'B', 'C']);
  });

  it('shows the "no longer editable" notice and reads the list again on a 409', async () => {
    reorderBallots.mockRejectedValue(new ApiError(409, 'election_not_editable'));
    fetchBallots.mockResolvedValue([three[0]]);
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    expect(await screen.findByTestId('ballots-notice')).toHaveTextContent(
      'Cette élection ne peut plus être modifiée',
    );
    await waitFor(() => expect(titles()).toEqual(['A']));
    expect(fetchBallots).toHaveBeenCalledWith(election.id);
  });

  it('keeps the form open with a message beside the title when it is empty', async () => {
    renderIn('fr', <BallotsPage election={election} initial={[]} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('ballots-add'));
    await userEvent.click(screen.getByTestId('ballot-form-save'));

    expect(screen.getByTestId('ballot-form-error-title')).toBeInTheDocument();
    expect(screen.getByTestId('ballot-form')).toBeInTheDocument();
    expect(createBallot).not.toHaveBeenCalled();
  });

  it('adds a ballot at the end with the values of the form', async () => {
    createBallot.mockResolvedValue(ballot('Délégués', 1, { seats: 2 }));
    renderIn('fr', <BallotsPage election={election} initial={[]} initialParties={[]} />);

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
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('ballot-delete-2'));

    expect(
      within(screen.getByTestId('ballot-delete-dialog')).getByText(/«\sB\s»/),
    ).toBeInTheDocument();
  });

  it('only shows the ballots of an election that is not a draft', () => {
    renderIn(
      'fr',
      <BallotsPage
        election={{ ...election, status: 'scheduled' }}
        initial={three}
        initialParties={[]}
      />,
    );

    expect(titles()).toEqual(['A', 'B', 'C']);
    expect(screen.queryByTestId('ballots-add')).not.toBeInTheDocument();
    expect(screen.queryByTestId('ballot-up-2')).not.toBeInTheDocument();
    expect(screen.queryByTestId('ballot-edit-1')).not.toBeInTheDocument();
    expect(screen.getByTestId('ballots-locked')).toBeInTheDocument();
  });
});

function party(name: string, extra: Partial<Party> = {}): Party {
  return {
    id: `20000000-0000-4000-8000-${name.length.toString().padStart(12, '0')}`,
    name,
    acronym: null,
    colour: '#5468D4',
    logo: null,
    candidates_count: 0,
    created_at: '2026-10-10T14:00:00Z',
    updated_at: '2026-10-10T14:00:00Z',
    ...extra,
  };
}

describe('BallotsPage parties', () => {
  it('shows the empty card and a count of zero', () => {
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    expect(screen.getByTestId('parties-count')).toHaveTextContent('0 parti');
    expect(screen.getByTestId('parties-empty')).toBeInTheDocument();
    expect(screen.getByTestId('party-new')).toBeInTheDocument();
  });

  it('shows a row per party with its swatch, acronym and count', () => {
    const parties = [
      party('Avenir Étudiant', { acronym: 'AE', colour: '#C2410C' }),
      party('Ensemble'),
    ];
    // The count is the real one: counted on the candidates the page holds.
    const held = [
      ballot('A', 1, {
        candidates: [candidate('a', 1, parties[0]!.id), candidate('b', 1, parties[0]!.id)],
      }),
    ];

    renderIn('fr', <BallotsPage election={election} initial={held} initialParties={parties} />);

    expect(screen.getByTestId('parties-count')).toHaveTextContent('2 partis');
    expect(screen.getByTestId('party-swatch-1')).toHaveTextContent('AE');
    expect(screen.getByTestId('party-swatch-2')).toHaveTextContent('EN');
    expect(screen.getByTestId('party-acronym-1')).toHaveTextContent('AE');
    expect(screen.queryByTestId('party-acronym-2')).toBeNull();
    expect(screen.getByTestId('party-count-1')).toHaveTextContent('2 candidats');
    expect(screen.getByTestId('party-count-2')).toHaveTextContent('Aucun candidat');
  });

  it('opens the modal, validates the name, saves and closes with the card updated', async () => {
    const saved = party('Ensemble');

    createParty.mockResolvedValue(saved);
    fetchParties.mockResolvedValue([saved]);
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('party-new'));
    expect(screen.getByTestId('party-modal')).toHaveAttribute('role', 'dialog');
    expect(screen.getByTestId('party-form-colour-5468D4')).toBeChecked();

    await userEvent.click(screen.getByTestId('party-form-save'));
    expect(screen.getByTestId('party-form-error-name')).toBeInTheDocument();
    expect(createParty).not.toHaveBeenCalled();

    await userEvent.type(screen.getByTestId('party-form-name'), 'Ensemble');
    await userEvent.click(screen.getByTestId('party-form-colour-C2410C'));
    await userEvent.click(screen.getByTestId('party-form-save'));

    await waitFor(() => expect(screen.queryByTestId('party-modal')).toBeNull());
    expect(createParty).toHaveBeenCalledWith(election.id, {
      name: 'Ensemble',
      acronym: null,
      colour: '#C2410C',
    });
    expect(screen.getByTestId('party-name-1')).toHaveTextContent('Ensemble');
    expect(screen.getByTestId('parties-count')).toHaveTextContent('1 parti');
  });

  it('shows the name already used beside the field and stays open', async () => {
    createParty.mockRejectedValue(new ApiError(422, 'validation_failed', { name: ['unique'] }));
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('parties-add'));
    await userEvent.type(screen.getByTestId('party-form-name'), 'Ensemble');
    await userEvent.click(screen.getByTestId('party-form-save'));

    expect(await screen.findByTestId('party-form-error-name')).toHaveTextContent('déjà');
    expect(screen.getByTestId('party-modal')).toBeInTheDocument();
  });

  it('shows the limit notice in the modal', async () => {
    createParty.mockRejectedValue(new ApiError(409, 'party_limit_reached'));
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('party-new'));
    await userEvent.type(screen.getByTestId('party-form-name'), 'Trente et un');
    await userEvent.click(screen.getByTestId('party-form-save'));

    expect(await screen.findByTestId('party-form-alert')).toHaveTextContent('30 partis');
    expect(screen.getByTestId('party-modal')).toBeInTheDocument();
  });

  it('keeps the modal open and clears it on "save and add another"', async () => {
    createParty.mockResolvedValue(party('Premier'));
    fetchParties.mockResolvedValue([party('Premier')]);
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('party-new'));
    await userEvent.type(screen.getByTestId('party-form-name'), 'Premier');
    await userEvent.click(screen.getByTestId('party-form-save-another'));

    await waitFor(() => expect(screen.getByTestId('party-form-name')).toHaveValue(''));
    expect(screen.getByTestId('party-modal')).toBeInTheDocument();
    expect(screen.getByTestId('party-form-name')).toHaveFocus();
    expect(screen.getByTestId('party-name-1')).toHaveTextContent('Premier');
  });

  it('closes with the close button without saving', async () => {
    renderIn('fr', <BallotsPage election={election} initial={three} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('party-new'));
    await userEvent.click(screen.getByTestId('party-modal-close'));
    expect(screen.queryByTestId('party-modal')).toBeNull();
    expect(createParty).not.toHaveBeenCalled();
  });

  it('asks before deleting, names the party, then removes the row', async () => {
    deleteParty.mockResolvedValue(undefined);
    renderIn(
      'fr',
      <BallotsPage election={election} initial={three} initialParties={[party('Alpha')]} />,
    );

    await userEvent.click(screen.getByTestId('party-delete-1'));
    expect(screen.getByTestId('party-delete-dialog')).toHaveTextContent('Alpha');
    expect(screen.getByTestId('party-delete-dialog')).toHaveTextContent('indépendants');

    await userEvent.click(screen.getByTestId('party-delete-confirm'));

    await waitFor(() => expect(screen.queryByTestId('party-row-1')).toBeNull());
    expect(deleteParty).toHaveBeenCalledWith(party('Alpha').id);
    expect(screen.getByTestId('parties-empty')).toBeInTheDocument();
  });

  it('has no party button on an election that is not a draft', () => {
    renderIn(
      'fr',
      <BallotsPage
        election={{ ...election, status: 'scheduled' }}
        initial={three}
        initialParties={[party('Alpha')]}
      />,
    );

    expect(screen.getByTestId('party-row-1')).toBeInTheDocument();
    expect(screen.queryByTestId('party-new')).toBeNull();
    expect(screen.queryByTestId('parties-add')).toBeNull();
    expect(screen.queryByTestId('party-edit-1')).toBeNull();
  });
});

describe('BallotsPage candidates', () => {
  // Other tests leave an answer for the list read after a save: start from none.
  beforeEach(() => {
    fetchBallots.mockReset();
    fetchParties.mockReset();
  });

  const named = [
    ballot('A', 1, {
      candidates: [candidate('a', 1), candidate('b', 1)],
      candidates_count: 2,
    }),
    ballot('B', 2, { candidates: [candidate('c', 2)], candidates_count: 1 }),
    ballot('C', 3),
  ];

  it('shows a row per candidate with the avatar by sex and the party or "Indépendant"', () => {
    const parties = [party('Ensemble')];
    const held = [
      ballot('A', 1, {
        candidates: [candidate('a', 1, parties[0]!.id), candidate('b', 1)],
        candidates_count: 2,
      }),
    ];

    renderIn('fr', <BallotsPage election={election} initial={held} initialParties={parties} />);

    expect(screen.getByTestId('candidate-name-1-1')).toHaveTextContent('a Nom');
    expect(screen.getByTestId('candidate-avatar-1-1')).toHaveAttribute('data-sex', 'male');
    expect(screen.getByTestId('candidate-avatar-1-2')).toHaveAttribute('data-sex', 'female');
    expect(screen.getByTestId('candidate-party-1-1')).toHaveTextContent('Ensemble');
    expect(screen.getByTestId('candidate-party-1-2')).toHaveTextContent('Indépendant');
    expect(screen.getByTestId('candidate-up-1-1')).toBeDisabled();
    expect(screen.getByTestId('candidate-down-1-2')).toBeDisabled();
    expect(screen.getByTestId('candidate-down-1-1')).toBeEnabled();
  });

  it('counts the candidates on the tags and the header chip', () => {
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    expect(screen.getByTestId('ballot-candidates-1')).toHaveTextContent('2 candidats');
    expect(screen.getByTestId('ballot-candidates-3')).toHaveTextContent('0 candidat');
    expect(screen.getByTestId('candidates-count')).toHaveTextContent('3 candidats');
  });

  it('warns about a single candidate and lists the ballots to complete', () => {
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    expect(screen.queryByTestId('ballot-warning-1')).toBeNull();
    expect(screen.getByTestId('ballot-warning-2')).toBeInTheDocument();
    expect(screen.queryByTestId('ballot-warning-3')).toBeNull();

    const checks = screen.getByTestId('ballots-checks');

    expect(checks).toHaveTextContent('B : un seul candidat');
    expect(checks).toHaveTextContent('C : aucun candidat');
    expect(checks).not.toHaveTextContent('A : ');
  });

  it('reorders the candidates of a ballot with the arrows and saves once', async () => {
    const user = userEvent.setup();
    const [first, second] = named[0]!.candidates;

    reorderCandidates.mockResolvedValue([second, first]);
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-down-1-1'));

    expect(screen.getAllByTestId(/^candidate-name-1-/).map((n) => n.textContent)).toEqual([
      'b Nom',
      'a Nom',
    ]);
    await waitFor(() => expect(reorderCandidates).toHaveBeenCalledTimes(1));
    expect(reorderCandidates).toHaveBeenCalledWith(named[0]!.id, [second!.id, first!.id]);
  });

  it('puts the old order back with a notice when the save fails', async () => {
    const user = userEvent.setup();

    reorderCandidates.mockRejectedValue(new ApiError(500, 'server_error'));
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-down-1-1'));

    await waitFor(() => expect(screen.getByTestId('ballots-notice')).toBeInTheDocument());
    expect(screen.getAllByTestId(/^candidate-name-1-/).map((n) => n.textContent)).toEqual([
      'a Nom',
      'b Nom',
    ]);
  });

  it('opens the modal for a ballot from the card and from the "to check" card', async () => {
    const user = userEvent.setup();

    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-add-2'));
    expect(screen.getByTestId('candidate-form-ballot')).toHaveValue(named[1]!.id);
    await user.click(screen.getByTestId('candidate-form-cancel'));
    expect(screen.queryByTestId('candidate-modal')).toBeNull();

    await user.click(screen.getByTestId('ballots-check-add-3'));
    expect(screen.getByTestId('candidate-form-ballot')).toHaveValue(named[2]!.id);
  });

  it('saves a new candidate and shows it in the card', async () => {
    const user = userEvent.setup();
    const saved = { ...candidate('z', 3), first_name: 'Zoé', last_name: 'Nom' };

    createCandidate.mockResolvedValue(saved);
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-add-3'));
    await user.type(screen.getByTestId('candidate-form-first-name'), 'Zoé');
    await user.type(screen.getByTestId('candidate-form-last-name'), 'Nom');
    await user.click(screen.getByTestId('candidate-form-save'));

    await waitFor(() => expect(screen.queryByTestId('candidate-modal')).toBeNull());
    expect(createCandidate).toHaveBeenCalledWith(
      named[2]!.id,
      expect.objectContaining({ first_name: 'Zoé', last_name: 'Nom', party: null }),
    );
    expect(screen.getByTestId('candidate-name-3-1')).toHaveTextContent('Zoé Nom');
  });

  it('shows the errors beside the names without calling the API', async () => {
    const user = userEvent.setup();

    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-add-1'));
    await user.click(screen.getByTestId('candidate-form-save'));

    expect(screen.getByTestId('candidate-form-error-first-name')).toBeInTheDocument();
    expect(screen.getByTestId('candidate-form-error-last-name')).toBeInTheDocument();
    expect(createCandidate).not.toHaveBeenCalled();
  });

  it('shows a full ballot as a notice in the modal', async () => {
    const user = userEvent.setup();

    createCandidate.mockRejectedValue(new ApiError(409, 'candidate_limit_reached'));
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-add-1'));
    await user.type(screen.getByTestId('candidate-form-first-name'), 'Un');
    await user.type(screen.getByTestId('candidate-form-last-name'), 'Autre');
    await user.click(screen.getByTestId('candidate-form-save'));

    expect(await screen.findByTestId('candidate-form-alert')).toHaveTextContent('50 candidats');
    expect(screen.getByTestId('candidate-modal')).toBeInTheDocument();
  });

  it('deletes after a confirmation that names the candidate', async () => {
    const user = userEvent.setup();

    deleteCandidate.mockResolvedValue(undefined);
    renderIn('fr', <BallotsPage election={election} initial={named} initialParties={[]} />);

    await user.click(screen.getByTestId('candidate-delete-1-1'));
    expect(screen.getByTestId('candidate-delete-dialog')).toHaveTextContent('a Nom');
    await user.click(screen.getByTestId('candidate-delete-confirm'));

    await waitFor(() => expect(screen.queryByTestId('candidate-delete-dialog')).toBeNull());
    expect(deleteCandidate).toHaveBeenCalledWith(named[0]!.candidates[0]!.id);
    expect(screen.getAllByTestId(/^candidate-name-1-/).map((n) => n.textContent)).toEqual([
      'b Nom',
    ]);
    expect(screen.getByTestId('ballot-warning-1')).toBeInTheDocument();
  });

  it('shows no candidate button and no arrows on an election that is not a draft', () => {
    renderIn(
      'fr',
      <BallotsPage
        election={{ ...election, status: 'open' }}
        initial={named}
        initialParties={[]}
      />,
    );

    expect(screen.getByTestId('candidate-name-1-1')).toBeInTheDocument();
    expect(screen.queryByTestId('candidate-add-1')).toBeNull();
    expect(screen.queryByTestId('candidate-up-1-1')).toBeNull();
    expect(screen.queryByTestId('ballots-check-add-3')).toBeNull();
  });
});

describe('BallotsPage after a reorder', () => {
  beforeEach(() => {
    fetchBallots.mockReset();
    fetchParties.mockReset();
  });

  const withTwo = [
    ballot('A', 1, {
      candidates: [candidate('a', 1), candidate('b', 1)],
      candidates_count: 2,
    }),
    ballot('B', 2),
  ];
  const mismatch = (field: string) =>
    new ApiError(422, 'validation_failed', { [field]: ['set_mismatch'] });

  it('reads the ballots again and tells so on a 422 set_mismatch', async () => {
    reorderBallots.mockRejectedValue(mismatch('ballots'));
    fetchBallots.mockResolvedValue([ballot('B', 2), ballot('A', 1), ballot('N', 4)]);
    renderIn('fr', <BallotsPage election={election} initial={withTwo} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('ballot-down-1'));

    await waitFor(() => expect(titles()).toEqual(['B', 'A', 'N']));
    expect(screen.getByTestId('ballots-notice')).toHaveTextContent('a changé ailleurs');
    expect(reorderBallots).toHaveBeenCalledTimes(1);
  });

  it('reads the candidates again and tells so on a 422 set_mismatch', async () => {
    reorderCandidates.mockRejectedValue(mismatch('candidates'));
    fetchBallots.mockResolvedValue([
      ballot('A', 1, {
        candidates: [candidate('c', 1), candidate('a', 1), candidate('b', 1)],
        candidates_count: 3,
      }),
      ballot('B', 2),
    ]);
    renderIn('fr', <BallotsPage election={election} initial={withTwo} initialParties={[]} />);

    await userEvent.click(screen.getByTestId('candidate-down-1-1'));

    await waitFor(() =>
      expect(screen.getAllByTestId(/^candidate-name-1-/).map((n) => n.textContent)).toEqual([
        'c Nom',
        'a Nom',
        'b Nom',
      ]),
    );
    expect(screen.getByTestId('ballots-notice')).toHaveTextContent('ont changé ailleurs');
  });

  it('reads the list again and closes the party dialog on a 409 from a party delete', async () => {
    deleteParty.mockRejectedValue(new ApiError(409, 'election_not_editable'));
    fetchBallots.mockResolvedValue(withTwo);
    renderIn(
      'fr',
      <BallotsPage election={election} initial={withTwo} initialParties={[party('Ensemble')]} />,
    );

    await userEvent.click(screen.getByTestId('party-delete-1'));
    await userEvent.click(await screen.findByTestId('party-delete-confirm'));

    expect(await screen.findByTestId('ballots-notice')).toHaveTextContent(
      'Cette élection ne peut plus être modifiée',
    );
    expect(fetchBallots).toHaveBeenCalledWith(election.id);
  });
});
