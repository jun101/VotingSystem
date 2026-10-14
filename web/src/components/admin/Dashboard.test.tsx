import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { CurrentUser } from '@/lib/api/user';
import { AdminUserProvider } from './AdminUser';
import { ComingSoon } from './ComingSoon';
import { Dashboard, type DashboardData } from './Dashboard';

vi.mock('@/lib/api/browser', () => ({ fetchDashboard: vi.fn() }));

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

const empty: DashboardData = {
  now: '2026-10-09T15:00:00Z',
  elections: [],
  counts: { all: 0, draft: 0, scheduled: 0, open: 0, closed: 0, published: 0, archived: 0 },
  institution: null,
  team: null,
  twoFactor: null,
};

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
        <Dashboard {...empty} />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-welcome')).toHaveTextContent('Marie Joseph');
    expect(screen.getByTestId('dashboard-institution')).toHaveTextContent('Collège Alpha');

    for (const block of [
      'open-election',
      'todo',
      'figures',
      'activity',
      'latest',
      'getting-started',
    ]) {
      expect(screen.getByTestId(`dashboard-card-${block}`)).toBeInTheDocument();
    }

    expect(screen.getByTestId('dashboard-create-election')).toHaveAttribute(
      'href',
      '/admin/elections/new',
    );
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getByTestId('dashboard-progress')).toHaveTextContent('33 %');
    expect(screen.getAllByTestId('empty-art').length).toBeGreaterThan(0);
  });

  it('shows the institution card in place of the first steps once the three are done', () => {
    const verified = { ...userWith({ id: '2', name: 'Collège Alpha', type: 'school' }) };
    renderIn(
      'fr',
      <AdminUserProvider user={verified}>
        <Dashboard
          {...empty}
          counts={{ ...empty.counts, all: 1, draft: 1 }}
          institution={
            {
              timezone: 'America/Port-au-Prince',
              logo: { sm: '/a.png', md: '/b.png', lg: '/c.png' },
            } as DashboardData['institution']
          }
          twoFactor={{ enabled: true, setup_started: false, recovery_codes_left: 8 }}
        />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-card-institution')).toHaveTextContent(
      'Activée pour votre compte',
    );
    expect(screen.queryByTestId('dashboard-card-getting-started')).not.toBeInTheDocument();
    expect(screen.getByTestId('dashboard-figure-elections')).toHaveTextContent('1');
    expect(screen.getByTestId('dashboard-figure-ballots')).toHaveTextContent('0');
  });

  it('shows no institution for a user who has none', () => {
    renderIn(
      'en',
      <AdminUserProvider user={userWith(null)}>
        <Dashboard {...empty} />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-welcome')).toBeInTheDocument();
    expect(screen.queryByTestId('dashboard-institution')).not.toBeInTheDocument();
  });
});

describe('Dashboard, live numbers', () => {
  const live: NonNullable<DashboardData['live']> = {
    counts: { draft: 1, scheduled: 1, open: 1, closed: 0, published: 0, archived: 0 },
    voters: 6,
    open: [
      {
        id: '33333333-3333-4333-8333-333333333333',
        title: 'Conseil des élèves',
        ends_at: '2026-10-12T19:00:00Z',
        eligible: 6,
        voted: 2,
        percentage: 33.3,
      },
    ],
    upcoming: [
      {
        id: '44444444-4444-4444-8444-444444444444',
        title: 'Délégués',
        starts_at: '2026-10-20T12:00:00Z',
      },
    ],
    todo: [
      {
        kind: 'voters_not_voted',
        election: { id: '33333333-3333-4333-8333-333333333333', title: 'Conseil des élèves' },
        count: 4,
      },
      {
        kind: 'codes_missing',
        election: { id: '44444444-4444-4444-8444-444444444444', title: 'Délégués' },
        count: 1,
      },
    ],
  };

  it('lists the open election with its turnout and links, and the to-do items with their counts', () => {
    renderIn(
      'fr',
      <AdminUserProvider user={userWith(null)}>
        <Dashboard {...empty} counts={{ ...empty.counts, all: 3, open: 1 }} live={live} />
      </AdminUserProvider>,
    );

    expect(screen.getByTestId('dashboard-election-1')).toHaveTextContent('Conseil des élèves');
    expect(screen.getByTestId('dashboard-turnout-1')).toHaveTextContent('33,3');
    expect(screen.getByTestId('dashboard-turnout-1')).toHaveTextContent('2 / 6');
    expect(screen.getByTestId('dashboard-election-turnout-1')).toHaveAttribute(
      'href',
      '/admin/elections/33333333-3333-4333-8333-333333333333/turnout',
    );
    expect(screen.getByTestId('dashboard-todo-1')).toHaveTextContent('4 électeurs');
    expect(screen.getByTestId('dashboard-todo-1')).toHaveAttribute(
      'href',
      '/admin/elections/33333333-3333-4333-8333-333333333333/turnout',
    );
    expect(screen.getByTestId('dashboard-todo-2')).toHaveAttribute(
      'href',
      '/admin/elections/44444444-4444-4444-8444-444444444444/codes',
    );
    expect(screen.getByTestId('dashboard-upcoming-1')).toHaveTextContent('Délégués');
    expect(screen.getByTestId('dashboard-count-open')).toHaveTextContent('1');
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
