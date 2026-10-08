import { beforeEach, describe, expect, it, vi } from 'vitest';

const notFound = vi.fn(() => {
  throw new Error('NEXT_NOT_FOUND');
});
const fetchSession = vi.fn();
const fetchInstitution = vi.fn();

vi.mock('next/navigation', () => ({ notFound: () => notFound() }));
vi.mock('@/lib/api/server', () => ({
  fetchSession: () => fetchSession(),
  fetchInstitution: () => fetchInstitution(),
  fetchTeam: vi.fn(),
  fetchInvitations: vi.fn(),
}));
vi.mock('@/lib/i18n/server', () => ({ getI18n: vi.fn(async () => ({ locale: 'fr' })) }));
vi.mock('@/components/institution/InstitutionPage', () => ({ InstitutionPage: () => null }));

import InstitutionRoute from './page';

describe('the institution route', () => {
  beforeEach(() => {
    notFound.mockClear();
    fetchSession.mockReset();
    fetchInstitution.mockReset();
  });

  it('answers not found when nobody is signed in', async () => {
    fetchSession.mockResolvedValue({ status: 'signed-out' });
    fetchInstitution.mockResolvedValue(null);

    await expect(InstitutionRoute()).rejects.toThrow('NEXT_NOT_FOUND');
    expect(notFound).toHaveBeenCalled();
  });

  it('shows the error page, not a 404, when the API fails for a signed-in visitor', async () => {
    fetchSession.mockResolvedValue({
      status: 'signed-in',
      user: { role: 'manager' },
    });
    fetchInstitution.mockResolvedValue(null);

    await expect(InstitutionRoute()).rejects.toThrow('could not be read');
    expect(notFound).not.toHaveBeenCalled();
  });
});
