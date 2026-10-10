'use client';

import { useState } from 'react';
import { Reveal } from '@/components/motion';
import { Card } from '@/components/ui';
import { useAdminUser } from '@/components/admin/AdminUser';
import type { InstitutionProfile, Listing, PendingInvitation, TeamMember } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import { ProfileCard } from './ProfileCard';
import { PublicAddress } from './PublicAddress';
import { UsersCard } from './UsersCard';

/**
 * Screen A14, "Établissement": on `lg` the profile on the left and the users on the right, on a
 * phone one under the other. An owner edits the profile and manages the users; a manager sees
 * the profile read-only and is told, politely, who manages the rest.
 */
export function InstitutionPage({
  profile: initialProfile,
  members,
  invitations,
}: {
  profile: InstitutionProfile;
  /** Null for a manager: the lists are for owners. */
  members: Listing<TeamMember> | null;
  invitations: Listing<PendingInvitation> | null;
}) {
  const { t } = useI18n();
  const user = useAdminUser();
  const owner = user.role === 'owner';
  const [profile, setProfile] = useState(initialProfile);

  return (
    <Reveal
      stagger
      data-testid="institution-page"
      className="grid gap-4 lg:grid-cols-2 lg:items-start"
    >
      <div className="flex min-w-0 flex-col gap-4">
        <ProfileCard profile={profile} canEdit={owner} onProfile={setProfile} />
        <PublicAddress id={profile.id} />
      </div>

      <div className="min-w-0">
        {owner && members && invitations ? (
          <UsersCard members={members} invitations={invitations} />
        ) : (
          <Card title={t('institution.users.managerNoteTitle')}>
            <p data-testid="users-manager-note" className="text-base text-ink-soft">
              {t('institution.users.managerNote')}
            </p>
          </Card>
        )}
      </div>
    </Reveal>
  );
}
