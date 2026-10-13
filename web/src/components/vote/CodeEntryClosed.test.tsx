import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import { CodeEntry } from './CodeEntry';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), replace: vi.fn() }) }));

let results: string | null = null;

vi.mock('./VoteProvider', () => ({
  useVote: () => ({
    election: '00000000-0000-4000-8000-0000000000e1',
    notice: null,
    setPhase: vi.fn(),
    info: {
      phase: 'closed',
      institution: 'FIC',
      title: 'Conseil des élèves',
      starts_at: '2026-10-13T04:00:00Z',
      ends_at: '2026-10-14T04:00:00Z',
      timezone: 'America/Port-au-Prince',
      results,
    },
  }),
}));

describe('CodeEntry, election closed', () => {
  it('says the vote is over, with the closing date, and no code field', () => {
    results = null;
    renderIn('fr', <CodeEntry />);

    expect(screen.getByTestId('vote-closed')).toBeInTheDocument();
    expect(screen.getByText('Le vote est terminé')).toBeInTheDocument();
    expect(screen.getByText(/Fermée : /)).toBeInTheDocument();
    expect(screen.queryByTestId('vote-code-input')).toBeNull();
  });

  it('says the results are coming, with no button, while they are not published', () => {
    results = null;
    renderIn('fr', <CodeEntry />);

    expect(screen.getByText('Les résultats arrivent')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Voir les résultats/ })).toBeNull();
  });

  it('shows the results button once they are published', () => {
    results = '/results/00000000-0000-4000-8000-0000000000e1';
    renderIn('fr', <CodeEntry />);

    expect(screen.getByText('Les résultats sont publiés')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Voir les résultats/ })).toHaveAttribute(
      'href',
      '/results/00000000-0000-4000-8000-0000000000e1',
    );
  });

  it('no longer explains how to vote', () => {
    results = null;
    renderIn('fr', <CodeEntry />);

    expect(screen.queryByText('Comment ça marche')).toBeNull();
  });
});
