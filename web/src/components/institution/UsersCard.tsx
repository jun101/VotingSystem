'use client';

import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { Button, Card, ConfirmDialog, Notice, Pill } from '@/components/ui';
import { initials } from '@/components/admin/menu';
import { cancelInvitation, removeUser } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import type { PendingInvitation, TeamMember } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import type { MessageKey } from '@/lib/i18n/messages';
import { InviteForm } from './InviteForm';

const ROLE_LABELS = {
  owner: 'admin.roles.owner',
  manager: 'admin.roles.manager',
} as const satisfies Record<'owner' | 'manager', MessageKey>;

const DAY = 86_400_000;

/**
 * The users of the institution (screen A14, right; owners only): one card per user and one
 * per invitation not yet accepted, the invitation form, and the confirmation before a user is
 * removed. The lists come from the server and change here as the API answers.
 */
export function UsersCard({
  members: initialMembers,
  invitations: initialInvitations,
}: {
  members: TeamMember[];
  invitations: PendingInvitation[];
}) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const card = useRef<HTMLElement>(null);
  const inviteButton = useRef<HTMLButtonElement>(null);
  const [members, setMembers] = useState(initialMembers);
  const [invitations, setInvitations] = useState(initialInvitations);
  const [inviting, setInviting] = useState(false);
  const [target, setTarget] = useState<TeamMember | null>(null);
  const [removing, setRemoving] = useState(false);
  const [removeError, setRemoveError] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  // "Pending for N days" is counted from the moment the page was drawn.
  const [now] = useState(() => Date.now());

  function readable(caught: unknown): string {
    return errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny);
  }

  function invited(invitation: PendingInvitation) {
    // A new invitation to an address replaces the old one: one card per address.
    setInvitations((current) => [
      invitation,
      ...current.filter((other) => other.email.toLowerCase() !== invitation.email.toLowerCase()),
    ]);
    setInviting(false);
    window.setTimeout(() => inviteButton.current?.focus(), 0);
  }

  async function cancel(invitation: PendingInvitation) {
    setProblem(null);

    try {
      await cancelInvitation(invitation.id);
      setInvitations((current) => current.filter((other) => other.id !== invitation.id));
      card.current?.focus();
    } catch (caught) {
      setProblem(readable(caught));
    }
  }

  async function confirmRemoval() {
    if (!target) return;

    setRemoving(true);
    setRemoveError(null);

    try {
      await removeUser(target.id);
    } catch (caught) {
      setRemoveError(readable(caught));
      setRemoving(false);

      return;
    }

    if (target.is_you) {
      // One's own session ended with the answer.
      router.push('/login');
      router.refresh();

      return;
    }

    setMembers((current) => current.filter((member) => member.id !== target.id));
    setTarget(null);
    setRemoving(false);
    card.current?.focus();
  }

  function closeDialog() {
    setTarget(null);
    setRemoveError(null);
  }

  function pending(invitation: PendingInvitation): string {
    if (invitation.expired) return t('institution.users.expired');

    const days = Math.max(0, Math.floor((now - Date.parse(invitation.created_at)) / DAY));

    if (days === 0) return t('institution.users.pendingToday');

    return days === 1
      ? t('institution.users.pendingOne')
      : t('institution.users.pendingMany', { count: days });
  }

  return (
    <Card
      ref={card}
      tabIndex={-1}
      title={t('institution.users.title')}
      data-testid="users-card"
      className="outline-none"
      actions={
        <Button
          ref={inviteButton}
          variant="primary"
          aria-expanded={inviting}
          onClick={() => setInviting((open) => !open)}
          data-testid="invite-open"
        >
          {t('institution.users.invite.open')}
        </Button>
      }
    >
      <div className="flex flex-col gap-5">
        {inviting ? <InviteForm onInvited={invited} onClose={() => setInviting(false)} /> : null}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="users-error">
            {problem}
          </Notice>
        ) : null}

        <ul className="flex flex-col gap-3">
          {members.map((member, index) => (
            <li
              key={member.id}
              data-testid={`user-card-${index + 1}`}
              className="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface-alt p-3"
            >
              <span
                aria-hidden="true"
                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warm text-sm font-bold text-surface"
              >
                {initials(member.name)}
              </span>
              <div className="flex min-w-0 flex-1 basis-40 flex-col">
                <span
                  data-testid={`user-name-${index + 1}`}
                  className="font-semibold break-words text-ink"
                >
                  {member.name}
                </span>
                <span
                  data-testid={`user-email-${index + 1}`}
                  className="text-sm break-all text-ink-soft"
                >
                  {member.email}
                </span>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Pill
                  tone={member.role === 'owner' ? 'primary' : 'neutral'}
                  data-testid={`user-role-${index + 1}`}
                >
                  {t(ROLE_LABELS[member.role])}
                </Pill>
                {member.is_you ? (
                  <Pill tone="teal" data-testid="user-you">
                    {t('institution.users.you')}
                  </Pill>
                ) : null}
              </div>
              <Button
                variant="danger"
                aria-label={t('institution.users.removeLabel', { name: member.name })}
                onClick={() => setTarget(member)}
                data-testid={`user-remove-${index + 1}`}
              >
                {t('institution.users.remove')}
              </Button>
            </li>
          ))}
        </ul>

        <section aria-labelledby="invitations-title" className="flex flex-col gap-3">
          <h3 id="invitations-title" className="text-md font-bold text-ink">
            {t('institution.users.invitationsTitle')}
          </h3>
          {invitations.length === 0 ? (
            <p className="text-base text-ink-soft">{t('institution.users.empty')}</p>
          ) : (
            <ul className="flex flex-col gap-3">
              {invitations.map((invitation, index) => (
                <li
                  key={invitation.id}
                  data-testid={`invitation-card-${index + 1}`}
                  className="flex flex-wrap items-center gap-3 rounded-md border border-dashed border-line-strong bg-surface p-3"
                >
                  <div className="flex min-w-0 flex-1 basis-40 flex-col">
                    <span
                      data-testid={`invitation-email-${index + 1}`}
                      className="font-semibold break-all text-ink"
                    >
                      {invitation.email}
                    </span>
                    <span
                      data-testid={`invitation-status-${index + 1}`}
                      className={
                        invitation.expired
                          ? 'text-sm font-medium text-warm-ink'
                          : 'text-sm text-ink-soft'
                      }
                    >
                      {pending(invitation)}
                    </span>
                  </div>
                  <Pill tone="neutral" data-testid={`invitation-role-${index + 1}`}>
                    {t(ROLE_LABELS[invitation.role])}
                  </Pill>
                  <Button
                    variant="secondary"
                    aria-label={t('institution.users.cancelLabel', { email: invitation.email })}
                    onClick={() => cancel(invitation)}
                    data-testid={`invitation-cancel-${index + 1}`}
                  >
                    {t('institution.users.cancel')}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {target ? (
        <ConfirmDialog
          title={t('institution.users.removeTitle', { name: target.name })}
          confirmLabel={t('institution.users.removeConfirm')}
          cancelLabel={t('institution.users.removeCancel')}
          busy={removing}
          error={removeError}
          onConfirm={confirmRemoval}
          onCancel={closeDialog}
          testIds={{
            dialog: 'user-remove-dialog',
            confirm: 'user-remove-confirm',
            cancel: 'user-remove-cancel',
            error: 'user-remove-error',
          }}
        >
          {t(target.is_you ? 'institution.users.removeSelfText' : 'institution.users.removeText', {
            name: target.name,
          })}
        </ConfirmDialog>
      ) : null}
    </Card>
  );
}
