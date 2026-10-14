import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ImportPage } from './ImportPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

vi.mock('@/lib/api/browser', () => ({ uploadVoterFile: vi.fn() }));

function election(status: ElectionStatus): Election {
  return {
    id: '00000000-0000-4000-8000-000000000001',
    title: 'Conseil des élèves 2026',
    description: null,
    status,
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
}

describe('ImportPage', () => {
  it.each(['draft', 'scheduled'] as const)('offers the drop zone in a %s election', (status) => {
    renderIn('fr', <ImportPage election={election(status)} />);

    expect(screen.getByTestId('import-dropzone')).toBeTruthy();
    expect(screen.queryByTestId('import-locked-notice')).toBeNull();
    expect(screen.getByTestId('import-step-1').getAttribute('aria-current')).toBe('step');
    expect(screen.getByTestId('import-upload')).toHaveProperty('disabled', true);
  });

  it.each(['open', 'closed', 'published', 'archived'] as const)(
    'shows the notice and no drop zone in a %s election',
    (status) => {
      renderIn('fr', <ImportPage election={election(status)} />);

      expect(screen.getByTestId('import-locked-notice')).toBeTruthy();
      expect(screen.queryByTestId('import-dropzone')).toBeNull();
      expect(screen.queryByTestId('import-file-input')).toBeNull();
      expect(screen.queryByTestId('import-upload')).toBeNull();
      // The template and the way back stay.
      expect(screen.getByTestId('import-template-preview')).toBeTruthy();
      expect(screen.getByTestId('import-back')).toBeTruthy();
    },
  );

  it('links the template downloads to the API with the format', () => {
    renderIn('en', <ImportPage election={election('draft')} />);

    expect(screen.getByTestId('import-template-xlsx').getAttribute('href')).toBe(
      '/api/v1/elections/00000000-0000-4000-8000-000000000001/voter-imports/template?format=xlsx',
    );
    expect(screen.getByTestId('import-template-csv').getAttribute('href')).toContain('format=csv');
  });
});
