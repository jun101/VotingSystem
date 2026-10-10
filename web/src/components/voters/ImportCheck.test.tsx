import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election } from '@/lib/api/elections';
import type { VoterImport } from '@/lib/api/voterImports';
import { ImportCheck } from './ImportCheck';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/lib/api/browser', () => ({
  cancelImport: vi.fn(),
  confirmImport: vi.fn(),
  fetchImportRows: vi.fn(),
  fetchVoterImport: vi.fn(),
  updateImportMapping: vi.fn(),
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
  ballots_count: 1,
  voters_count: 0,
  created_at: '2026-10-08T15:20:00Z',
};

function voterImport(patch: Partial<VoterImport>): VoterImport {
  return {
    id: '00000000-0000-4000-8000-0000000000aa',
    election: election.id,
    status: 'failed',
    original_name: 'eleves.csv',
    columns: [],
    mapping: null,
    total_rows: 0,
    valid_rows: 0,
    invalid_rows: 0,
    duplicate_rows: 0,
    new_groups: [],
    processed_rows: 0,
    imported_rows: 0,
    failure: 'no_full_name_column',
    has_error_file: false,
    created_at: '2026-10-10T18:02:11Z',
    finished_at: null,
    ...patch,
  };
}

describe('ImportCheck', () => {
  it('shows the failure in words, a way back and no confirm button for a failed import', () => {
    renderIn('fr', <ImportCheck election={election} initial={voterImport({})} />);

    const failed = screen.getByTestId('check-failed');

    expect(failed.textContent).toContain('nom complet');
    expect(failed.textContent).not.toContain('no_full_name_column');
    expect(screen.getByTestId('check-retry').getAttribute('href')).toBe(
      `/admin/elections/${election.id}/voters/import`,
    );
    expect(screen.queryByTestId('check-confirm')).toBeNull();
    expect(screen.queryByTestId('check-rows')).toBeNull();
    expect(screen.getByTestId('import-step-3').getAttribute('aria-current')).toBe('step');
  });

  it('shows the spinner while the file is being checked, with no confirm button', () => {
    renderIn(
      'en',
      <ImportCheck election={election} initial={voterImport({ status: 'uploaded' })} />,
    );

    expect(screen.getByTestId('check-checking')).toBeTruthy();
    expect(screen.queryByTestId('check-confirm')).toBeNull();
  });

  it('shows the progress of a running import', () => {
    renderIn(
      'fr',
      <ImportCheck
        election={election}
        initial={voterImport({
          status: 'importing',
          failure: null,
          total_rows: 300,
          valid_rows: 300,
          processed_rows: 120,
        })}
      />,
    );

    expect(screen.getByTestId('import-progress').getAttribute('aria-valuenow')).toBe('40');
    expect(screen.getByTestId('import-progress-text').textContent).toBe('120 sur 300');
    expect(screen.getByTestId('import-step-4').getAttribute('aria-current')).toBe('step');
  });
});
