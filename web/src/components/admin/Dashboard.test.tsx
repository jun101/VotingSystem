import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { CurrentUser } from '@/lib/api/user';
import { AdminUserProvider } from './AdminUser';
import { ComingSoon } from './ComingSoon';
import { Dashboard } from './Dashboard';

function userWith(institution: CurrentUser['institution']): CurrentUser {
  return {
    id: '11111111-1111-4111-8111-111111111111',
    name: 'Marie Joseph',
    email: 'marie@example.test',
    email_verified: true,
    role: 'owner',
    language: 'fr',
    institution,
  };
}

describe('Dashboard', () => {
  it('welcomes the user and names the institution, with one card for each block', () => {
    renderIn(
      'fr',
      <AdminUserProvider
        user={userWith({
          id: '22222222-2222-4222-8222-222222222222',
          name: 'Collège Alpha',
          type: 'school',
        })}
      >
        <Dashboard />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-welcome')).toHaveTextContent('Marie Joseph');
    expect(screen.getByTestId('dashboard-institution')).toHaveTextContent('Collège Alpha');

    for (const block of ['open-election', 'todo', 'figures', 'activity', 'latest']) {
      expect(screen.getByTestId(`dashboard-card-${block}`)).toBeInTheDocument();
    }

    expect(screen.getByTestId('dashboard-create-election')).toHaveAttribute(
      'href',
      '/admin/elections/new',
    );
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('shows no institution for a user who has none', () => {
    renderIn(
      'en',
      <AdminUserProvider user={userWith(null)}>
        <Dashboard />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-welcome')).toBeInTheDocument();
    expect(screen.queryByTestId('dashboard-institution')).not.toBeInTheDocument();
  });
});

describe('ComingSoon', () => {
  it('says the page is not available yet, politely, in each language', () => {
    renderIn('fr', <ComingSoon />);
    expect(screen.getByTestId('coming-soon')).toHaveTextContent('disponible');
  });

  it('does not use the French word in English', () => {
    renderIn('en', <ComingSoon />);
    expect(screen.getByTestId('coming-soon')).not.toHaveTextContent('disponible');
  });
});
