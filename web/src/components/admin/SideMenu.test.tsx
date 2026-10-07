import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { CurrentUser } from '@/lib/api/user';
import { SideMenu } from './SideMenu';

const push = vi.fn();

vi.mock('next/navigation', () => ({
  usePathname: () => '/admin/elections/new',
  useRouter: () => ({ push, refresh: vi.fn() }),
}));
vi.mock('@/lib/api/browser', () => ({ updateLanguage: vi.fn() }));

const user: CurrentUser = {
  id: '11111111-1111-4111-8111-111111111111',
  name: 'Marie Joseph',
  email: 'marie@example.test',
  email_verified: true,
  role: 'owner',
  language: 'fr',
  institution: {
    id: '22222222-2222-4222-8222-222222222222',
    name: 'Collège Alpha',
    type: 'school',
  },
};

function show(locale: 'fr' | 'en') {
  const onNavigate = vi.fn();

  renderIn(
    locale,
    <SideMenu
      user={user}
      searchRef={createRef<HTMLInputElement>()}
      closeRef={createRef<HTMLButtonElement>()}
      onNavigate={onNavigate}
    />,
  );

  return onNavigate;
}

describe('SideMenu', () => {
  it('marks only Élections as current on the new-election page', () => {
    show('fr');

    const current = document.querySelectorAll('[aria-current="page"]');

    expect(current).toHaveLength(1);
    expect(current[0]).toHaveAttribute('data-testid', 'menu-link-elections');
  });

  it('shows the empty election card and no election section', () => {
    show('en');

    expect(screen.getByTestId('menu-election-card')).toHaveTextContent('No election selected');
    expect(screen.queryByTestId('menu-election-section')).not.toBeInTheDocument();
    expect(screen.getByTestId('menu-user-role')).toHaveTextContent('Owner');
  });

  it('filters the entries by their label, ignoring case and accents, and opens the first match', async () => {
    const onNavigate = show('fr');
    const search = screen.getByTestId('menu-search');

    await userEvent.type(search, 'ELECTION');
    expect(screen.getByTestId('menu-link-elections')).toBeInTheDocument();
    expect(screen.queryByTestId('menu-link-audit')).not.toBeInTheDocument();

    await userEvent.type(search, '{Enter}');
    expect(push).toHaveBeenCalledWith('/admin/elections');
    expect(onNavigate).toHaveBeenCalled();
  });

  it('says so when nothing matches, and goes nowhere on Enter', async () => {
    push.mockClear();
    show('fr');

    await userEvent.type(screen.getByTestId('menu-search'), 'zzzz{Enter}');

    expect(screen.getByTestId('menu-search-empty')).toBeInTheDocument();
    expect(push).not.toHaveBeenCalled();
  });
});
