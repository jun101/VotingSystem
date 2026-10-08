import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { CurrentUser } from '@/lib/api/user';
import { AdminShell } from './AdminShell';

vi.mock('next/navigation', () => ({
  usePathname: () => '/admin',
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('@/lib/api/browser', () => ({ updateLanguage: vi.fn(), logout: vi.fn() }));

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

let listener: ((event: { matches: boolean }) => void) | undefined;

beforeEach(() => {
  listener = undefined;
  vi.stubGlobal(
    'matchMedia',
    vi.fn().mockImplementation(() => ({
      matches: false,
      addEventListener: (_: string, fn: (event: { matches: boolean }) => void) => {
        listener = fn;
      },
      removeEventListener: () => {
        listener = undefined;
      },
    })),
  );
});

afterEach(() => vi.unstubAllGlobals());

describe('AdminShell', () => {
  it('closes the drawer, and frees the content, when the screen reaches the lg width', async () => {
    renderIn('fr', <AdminShell user={user}>{'content'}</AdminShell>);
    const content = document.getElementById('admin-content') as HTMLElement;

    await userEvent.click(screen.getByTestId('menu-button'));
    expect(screen.getByTestId('menu-drawer').dataset.open).toBe('true');
    expect(content.parentElement?.hasAttribute('inert')).toBe(true);

    act(() => listener?.({ matches: true }));

    expect(screen.getByTestId('menu-drawer').dataset.open).toBe('false');
    expect(content.parentElement?.hasAttribute('inert')).toBe(false);
  });
});
