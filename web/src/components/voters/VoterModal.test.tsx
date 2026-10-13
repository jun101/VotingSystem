import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Voter } from '@/lib/api/voters';
import { VoterModal } from './VoterModal';

const updateVoter = vi.fn();
const createVoter = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  updateVoter: (...args: unknown[]) => updateVoter(...args),
  createVoter: (...args: unknown[]) => createVoter(...args),
}));

const GROUPS = [
  { id: '00000000-0000-4000-8000-0000000000a1', name: '4e année' },
  { id: '00000000-0000-4000-8000-0000000000a2', name: '5e année' },
];

function voter(hasVoted: boolean): Voter {
  return {
    id: '00000000-0000-4000-8000-0000000000b1',
    full_name: 'Rose Désir',
    group: GROUPS[0]!,
    identifier: null,
    email: null,
    phone: null,
    has_code: true,
    email_status: null,
    has_voted: hasVoted,
    voted_at: hasVoted ? '2026-10-13T12:00:00Z' : null,
    created_at: '2026-10-08T15:20:00Z',
    updated_at: '2026-10-08T15:20:00Z',
  } as Voter;
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
  updateVoter.mockReset();
  createVoter.mockReset();
  updateVoter.mockResolvedValue(voter(false));
});

function show(target: Voter, groupsFixed: boolean) {
  return renderIn(
    'fr',
    <VoterModal
      voter={target}
      election="00000000-0000-4000-8000-0000000000e1"
      groups={GROUPS}
      groupsFixed={groupsFixed}
      onSaved={vi.fn()}
      onLocked={vi.fn()}
      onClose={vi.fn()}
    />,
  );
}

describe('VoterModal in an open election', () => {
  it('moves a voter who has not voted to an existing group', async () => {
    const user = userEvent.setup();

    show(voter(false), true);

    const group = screen.getByTestId('voter-form-group');

    expect(group).toBeEnabled();

    await user.clear(group);
    await user.type(group, '5e année');
    await user.click(screen.getByTestId('voter-form-save'));

    await waitFor(() => expect(updateVoter).toHaveBeenCalled());

    expect(updateVoter.mock.calls[0]?.[1]).toMatchObject({ group: '5e année' });
  });

  it('says a new group is not created while voting, and sends nothing', async () => {
    const user = userEvent.setup();

    show(voter(false), true);

    const group = screen.getByTestId('voter-form-group');

    await user.clear(group);
    await user.type(group, '6e année');
    await user.click(screen.getByTestId('voter-form-save'));

    expect(await screen.findByTestId('voter-form-error-group')).toBeInTheDocument();
    expect(updateVoter).not.toHaveBeenCalled();
  });

  it('locks the group of a voter who has voted', () => {
    show(voter(true), true);

    expect(screen.getByTestId('voter-form-group')).toBeDisabled();
  });

  it('leaves the group free in a draft election', async () => {
    const user = userEvent.setup();

    show(voter(false), false);

    const group = screen.getByTestId('voter-form-group');

    await user.clear(group);
    await user.type(group, '6e année');
    await user.click(screen.getByTestId('voter-form-save'));

    await waitFor(() => expect(updateVoter).toHaveBeenCalled());
  });
});
