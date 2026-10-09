import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election, ElectionList } from '@/lib/api/elections';
import { ApiError } from '@/lib/api/errors';
import { ElectionsPage } from './ElectionsPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));
const fetchElectionsPage = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  duplicateElection: vi.fn(),
  deleteElection: vi.fn(),
  fetchElectionsPage: (...args: unknown[]) => fetchElectionsPage(...args),
}));

function election(n: number, status: Election['status'] = 'draft'): Election {
  return {
    id: `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
    title: `Élection ${n}`,
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

const counts = { all: 0, draft: 0, scheduled: 0, open: 0, closed: 0, published: 0, archived: 0 };

function list(items: Election[], overrides: Partial<ElectionList> = {}): ElectionList {
  return { items, total: items.length, counts, years: [], ...overrides };
}

describe('ElectionsPage', () => {
  it('shows one invitation and only the "all" tile for an institution with no election (fr)', () => {
    renderIn('fr', <ElectionsPage list={list([])} filters={{}} />);

    expect(screen.getByTestId('election-new-tile')).toBeInTheDocument();
    expect(screen.getByTestId('elections-empty')).toBeInTheDocument();
    expect(screen.getByTestId('tile-all')).toHaveAttribute('data-count', '0');
    expect(screen.queryByTestId('tile-draft')).not.toBeInTheDocument();
    expect(screen.queryByTestId('year-chips')).not.toBeInTheDocument();
  });

  it('draws a tile per status that has elections, marks the current filter and the year', () => {
    renderIn(
      'fr',
      <ElectionsPage
        list={list([election(1), election(2, 'open')], {
          counts: { ...counts, all: 2, draft: 1, open: 1 },
          years: [2026, 2025],
        })}
        filters={{ status: 'draft', year: 2026 }}
      />,
    );

    expect(screen.getByTestId('tile-draft')).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('tile-draft')).toHaveAttribute('data-active', 'true');
    expect(screen.getByTestId('tile-all')).toHaveAttribute('data-active', 'false');
    expect(screen.getByTestId('tile-open')).toHaveAttribute('data-count', '1');
    expect(screen.queryByTestId('tile-closed')).not.toBeInTheDocument();
    expect(screen.getByTestId('year-2026')).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByTestId('year-2025')).toHaveAttribute('aria-pressed', 'false');
  });

  it('numbers the cards from 1 and offers delete for a draft only', () => {
    renderIn(
      'en',
      <ElectionsPage
        list={list([election(1), election(2, 'closed')], {
          counts: { ...counts, all: 2, draft: 1, closed: 1 },
        })}
        filters={{}}
      />,
    );

    expect(screen.getByTestId('election-status-1')).toHaveTextContent('Draft');
    expect(screen.getByTestId('election-open-1')).toHaveTextContent('Continue');
    expect(screen.getByTestId('election-delete-1')).toBeInTheDocument();
    expect(screen.getByTestId('election-status-2')).toHaveTextContent('Closed');
    expect(screen.getByTestId('election-open-2')).toHaveTextContent('Open');
    expect(screen.queryByTestId('election-delete-2')).not.toBeInTheDocument();
    expect(screen.getByTestId('election-dates-1')).toHaveTextContent('Oct 12 to 16, 2026');
    expect(screen.getByTestId('election-card-1')).toHaveTextContent('No positions · no voters');
  });

  describe('show more', () => {
    beforeEach(() => {
      fetchElectionsPage.mockReset();
    });

    it('has no button when everything is shown', () => {
      renderIn('en', <ElectionsPage list={list([election(1)])} filters={{}} />);

      expect(screen.queryByTestId('elections-show-more')).not.toBeInTheDocument();
    });

    it('reads the next page with the filters, appends it, then hides the button', async () => {
      fetchElectionsPage.mockResolvedValue({ items: [election(3), election(4)], total: 4 });
      renderIn(
        'en',
        <ElectionsPage
          list={list([election(1), election(2)], { total: 4 })}
          filters={{ status: 'draft', year: 2026 }}
        />,
      );

      await userEvent.click(screen.getByTestId('elections-show-more'));

      await waitFor(() => expect(screen.getByTestId('election-card-4')).toBeInTheDocument());
      expect(fetchElectionsPage).toHaveBeenCalledWith({ status: 'draft', year: 2026 }, 2);
      expect(screen.getByTestId('election-card-1')).toBeInTheDocument();
      expect(screen.queryByTestId('elections-show-more')).not.toBeInTheDocument();
    });

    it('does not add a card twice and stops when a page brings nothing new', async () => {
      fetchElectionsPage.mockResolvedValue({ items: [election(1)], total: 5 });
      renderIn('en', <ElectionsPage list={list([election(1)], { total: 5 })} filters={{}} />);

      await userEvent.click(screen.getByTestId('elections-show-more'));

      await waitFor(() =>
        expect(screen.queryByTestId('elections-show-more')).not.toBeInTheDocument(),
      );
      expect(screen.getAllByTestId(/^election-card-/)).toHaveLength(1);
    });

    it('says so when the page cannot be read, and keeps the button', async () => {
      fetchElectionsPage.mockImplementation(async () => {
        throw new ApiError(500, 'server_error');
      });
      renderIn('en', <ElectionsPage list={list([election(1)], { total: 2 })} filters={{}} />);

      await userEvent.click(screen.getByTestId('elections-show-more'));

      expect(await screen.findByTestId('elections-action-error')).toBeInTheDocument();
      expect(screen.getByTestId('elections-show-more')).toBeInTheDocument();
    });
  });
});
