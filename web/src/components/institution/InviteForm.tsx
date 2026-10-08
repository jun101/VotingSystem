'use client';

import { useEffect, useRef, type FormEvent } from 'react';
import { Button, Input, Notice, Select } from '@/components/ui';
import { useAuthForm } from '@/components/auth/useAuthForm';
import { inviteUser } from '@/lib/api/browser';
import type { InvitedRole, PendingInvitation } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';

const FIELDS = ['email', 'role'] as const;

/**
 * The inline form that invites a person by email, as owner or manager. A refusal is told under
 * the field and the first field in error gets the focus; the form is shown for as long as the
 * person wants it, and closes itself once the invitation is sent.
 */
export function InviteForm({
  onInvited,
  onClose,
}: {
  onInvited: (invitation: PendingInvitation) => void;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const { form, busy, fields, formError, run, idle } = useAuthForm(FIELDS, 'invite');
  const email = useRef<HTMLInputElement>(null);

  // The form opens on the field to fill.
  useEffect(() => email.current?.focus(), []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const done = await run(async () => {
      const invitation = await inviteUser({
        email: String(data.get('email') ?? ''),
        role: String(data.get('role') ?? '') as InvitedRole,
      });

      onInvited(invitation);
    });

    if (done) idle();
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      onSubmit={submit}
      data-testid="invite-form"
      aria-labelledby="invite-title"
      className="flex flex-col gap-4 rounded-md border border-primary-line bg-primary-soft p-4"
    >
      <h3 id="invite-title" className="text-md font-bold text-ink">
        {t('institution.users.invite.title')}
      </h3>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="invite-form-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        ref={email}
        label={t('institution.users.invite.email')}
        name="email"
        type="email"
        autoComplete="off"
        autoCapitalize="none"
        spellCheck={false}
        required
        data-testid="invite-email"
        error={fields.email}
        errorTestId="invite-email-error"
      />
      <Select
        label={t('institution.users.invite.role')}
        name="role"
        defaultValue="manager"
        data-testid="invite-role"
        error={fields.role}
        errorTestId="invite-role-error"
      >
        <option value="manager">{t('institution.users.invite.roles.manager')}</option>
        <option value="owner">{t('institution.users.invite.roles.owner')}</option>
      </Select>

      <div className="flex flex-wrap gap-3">
        <Button type="submit" loading={busy} data-testid="invite-submit">
          {t('institution.users.invite.submit')}
        </Button>
        <Button variant="secondary" onClick={onClose} data-testid="invite-close">
          {t('institution.users.invite.close')}
        </Button>
      </div>
    </form>
  );
}
