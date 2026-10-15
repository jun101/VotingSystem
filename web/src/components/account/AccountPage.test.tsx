import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { CurrentUser } from '@/lib/api/user';
import { AccountPage } from './AccountPage';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('@/lib/api/browser', () => ({
  startTwoFactorSetup: vi.fn(),
  confirmTwoFactor: vi.fn(),
  disableTwoFactor: vi.fn(),
  renewRecoveryCodes: vi.fn(),
  changePassword: vi.fn(),
  updateLanguage: vi.fn(),
}));
vi.mock('./QrCode', () => ({ QrCode: () => null }));

const OFF = { enabled: false, setup_started: false, recovery_codes_left: null };

// A platform admin: no institution, and no admin layout around the page (`/platform`).
const PLATFORM_ADMIN = {
  id: '5b6b4f5e-0d6e-4c3a-9a43-0d2a2f0a9b11',
  name: 'Platform Admin',
  email: 'admin@vote.test',
  role: 'platform_admin',
  email_verified: true,
  language: 'fr',
  institution: null,
} as unknown as CurrentUser;

describe('AccountPage outside the admin layout', () => {
  it('renders for a platform admin when the user is given as a prop', () => {
    renderIn('fr', <AccountPage twoFactor={OFF} user={PLATFORM_ADMIN} />);

    expect(screen.getByTestId('account-name').textContent).toBe('Platform Admin');
    expect(screen.getByTestId('security-card')).toBeTruthy();
    expect(screen.getByTestId('change-password-card')).toBeTruthy();
    // No institution: no institution card.
    expect(screen.queryByTestId('account-institution-link')).toBeNull();
  });

  it('fails clearly when there is neither a layout nor a user', () => {
    const quiet = vi.spyOn(console, 'error').mockImplementation(() => undefined);

    expect(() => renderIn('fr', <AccountPage twoFactor={OFF} />)).toThrow(/AdminUserProvider/);
    quiet.mockRestore();
  });
});
