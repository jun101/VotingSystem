import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { CurrentUser } from '@/lib/api/user';
import { renderIn } from '@/components/auth/testing';
import { AdminPlaceholder } from './AdminPlaceholder';

vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock('@/lib/api/browser', () => ({ logout: vi.fn(), resendVerification: vi.fn() }));

function userWith(institution: CurrentUser['institution']): CurrentUser {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Marie Joseph',
    email: 'marie@example.test',
    email_verified: true,
    role: 'owner',
    language: 'fr',
    institution,
  } as CurrentUser;
}

describe('AdminPlaceholder', () => {
  it('shows the institution of the user', () => {
    renderIn(
      'en',
      <AdminPlaceholder
        user={userWith({
          id: '22222222-2222-4222-8222-222222222222',
          name: 'Collège Alpha',
        } as CurrentUser['institution'])}
      />,
    );

    expect(screen.getByTestId('admin-institution')).toHaveTextContent('Collège Alpha');
  });

  it('shows no institution for a platform admin, who has none', () => {
    renderIn('en', <AdminPlaceholder user={userWith(null)} />);

    expect(screen.getByTestId('admin-welcome')).toBeInTheDocument();
    expect(screen.queryByTestId('admin-institution')).not.toBeInTheDocument();
    expect(screen.getByTestId('signout-button')).toBeInTheDocument();
  });
});
