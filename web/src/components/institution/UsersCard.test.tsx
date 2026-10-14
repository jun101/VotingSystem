import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { renderIn } from '@/components/auth/testing';
import type { Listing, PendingInvitation, TeamMember } from '@/lib/api/user';
import { UsersCard } from './UsersCard';

vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('@/lib/api/browser', () => ({
  cancelInvitation: vi.fn(),
  removeUser: vi.fn(),
  resetUserTwoFactor: vi.fn(),
  resetUserPassword: vi.fn(),
  createTeamMember: vi.fn(),
}));

function member(n: number): TeamMember {
  return {
    id: `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`,
    name: `Membre ${n}`,
    email: `membre${n}@example.test`,
    role: 'manager',
    is_you: false,
  } as TeamMember;
}

function invitation(n: number): PendingInvitation {
  return {
    id: `11111111-0000-4000-8000-${String(n).padStart(12, '0')}`,
    email: `invite${n}@example.test`,
    role: 'manager',
    expired: false,
    created_at: '2026-10-01T10:00:00Z',
  } as PendingInvitation;
}

function listing<T>(make: (n: number) => T, count: number, total: number): Listing<T> {
  return { items: Array.from({ length: count }, (_, i) => make(i + 1)), total };
}

describe('UsersCard', () => {
  it('says how many of the users and invitations are shown when there are more (fr)', () => {
    renderIn(
      'fr',
      <UsersCard members={listing(member, 100, 130)} invitations={listing(invitation, 100, 101)} />,
    );

    expect(screen.getByTestId('users-truncated')).toHaveTextContent('100 sur 130');
    expect(screen.getByTestId('invitations-truncated')).toHaveTextContent('100 sur 101');
  });

  it('says it in English too', () => {
    renderIn(
      'en',
      <UsersCard members={listing(member, 100, 130)} invitations={listing(invitation, 2, 2)} />,
    );

    expect(screen.getByTestId('users-truncated')).toHaveTextContent('100 of 130');
    expect(screen.queryByTestId('invitations-truncated')).not.toBeInTheDocument();
  });

  it('says nothing when everything is shown', () => {
    renderIn(
      'fr',
      <UsersCard members={listing(member, 3, 3)} invitations={listing(invitation, 0, 0)} />,
    );

    expect(screen.queryByTestId('users-truncated')).not.toBeInTheDocument();
    expect(screen.queryByTestId('invitations-truncated')).not.toBeInTheDocument();
  });

  it('offers to create a member and reset a password, and no invitation, in school mode (en)', () => {
    renderIn(
      'en',
      <UsersCard
        members={listing(member, 2, 2)}
        invitations={listing(invitation, 0, 0)}
        schoolMode
      />,
    );

    expect(screen.getByTestId('create-member-open')).toHaveTextContent('Create a member');
    expect(screen.queryByTestId('invite-open')).not.toBeInTheDocument();
    expect(screen.queryByText('Pending invitations')).not.toBeInTheDocument();
    expect(screen.getByTestId('user-reset-password-1')).toHaveTextContent('New password');
  });

  it('keeps the invitation button in online mode (fr)', () => {
    renderIn(
      'fr',
      <UsersCard members={listing(member, 1, 1)} invitations={listing(invitation, 0, 0)} />,
    );

    expect(screen.getByTestId('invite-open')).toBeInTheDocument();
    expect(screen.queryByTestId('create-member-open')).not.toBeInTheDocument();
    expect(screen.queryByTestId('user-reset-password-1')).not.toBeInTheDocument();
  });
});
