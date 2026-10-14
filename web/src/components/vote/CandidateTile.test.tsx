import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { VoteCandidate } from '@/lib/api/vote';
import { CandidateTile } from './CandidateTile';

const candidate = {
  id: '00000000-0000-4000-8000-0000000000c1',
  first_name: 'Nadège',
  last_name: 'Pierre-Louis',
  sex: 'female',
  slogan: null,
  photo: null,
  party: null,
} as unknown as VoteCandidate;

function show(over: { selected?: boolean; locked?: boolean; index?: number } = {}) {
  const onToggle = vi.fn();

  renderIn(
    'fr',
    <CandidateTile
      candidate={candidate}
      index={over.index ?? 1}
      selected={over.selected ?? false}
      locked={over.locked ?? false}
      onToggle={onToggle}
    />,
  );

  return onToggle;
}

describe('CandidateTile', () => {
  it('is a button that says whether it is chosen', () => {
    show({ selected: true });

    expect(screen.getByTestId('vote-candidate-1')).toHaveAttribute('aria-pressed', 'true');
  });

  it('toggles on a click', async () => {
    const onToggle = show();

    await userEvent.setup().click(screen.getByTestId('vote-candidate-1'));

    expect(onToggle).toHaveBeenCalledTimes(1);
  });

  it('is disabled and faded when the ballot is full and it is not chosen', () => {
    show({ locked: true });

    const tile = screen.getByTestId('vote-candidate-1');

    expect(tile).toBeDisabled();
    expect(tile.className).toContain('opacity-60');
  });

  it('staggers the entrance by position, never more than eight steps', () => {
    show({ index: 20 });

    expect(screen.getByTestId('vote-candidate-20')).toHaveStyle({ animationDelay: '960ms' });
  });

  it('shows the chosen mark and the independent label without a party', () => {
    show({ selected: true });

    expect(document.querySelector('.cand-check')).not.toBeNull();
    expect(screen.getByText('Indépendant')).toBeInTheDocument();
  });
});
