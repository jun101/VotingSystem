import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election } from '@/lib/api/elections';
import { ElectionSummary } from './ElectionSummary';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const fetchChecklist = vi.fn();

vi.mock('@/lib/api/browser', () => ({
  duplicateElection: vi.fn(),
  fetchChecklist: (...args: unknown[]) => fetchChecklist(...args),
  deleteElection: vi.fn(),
  scheduleElection: vi.fn(),
  unscheduleElection: vi.fn(),
}));

const DRAFT: Election = {
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
  ballots_count: 1,
  voters_count: 2,
  created_at: '2026-10-08T15:20:00Z',
};

beforeEach(() => fetchChecklist.mockReset());

describe('ElectionSummary checklist', () => {
  it('shows a retry control when the read fails, keeps scheduling disabled, and reads again', async () => {
    const user = userEvent.setup();

    fetchChecklist.mockRejectedValueOnce(new Error('down'));
    fetchChecklist.mockResolvedValueOnce({ blockers: [], warnings: [] });

    renderIn('fr', <ElectionSummary election={DRAFT} />);

    const retry = await screen.findByTestId('checklist-retry');

    expect(screen.getByTestId('election-schedule-button')).toBeDisabled();

    await user.click(retry);

    await waitFor(() => expect(screen.getByTestId('checklist-ok')).toBeInTheDocument());

    expect(screen.queryByTestId('checklist-retry')).not.toBeInTheDocument();
    expect(screen.getByTestId('election-schedule-button')).toBeEnabled();
    expect(fetchChecklist).toHaveBeenCalledTimes(2);
  });
});
