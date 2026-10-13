'use client';

import { useEffect, useRef, type FormEvent } from 'react';
import { SecondFactorField, useSecondFactor } from '@/components/account/SecondFactorField';
import { PasswordField } from '@/components/auth/PasswordField';
import { useAuthForm } from '@/components/auth/useAuthForm';
import { Button, Input, Notice, Select } from '@/components/ui';
import { createTeamMember } from '@/lib/api/browser';
import type { InvitedRole, TeamMember } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';

const FIELDS = ['name', 'email', 'role', 'password', 'code', 'recovery_code'] as const;

/**
 * School mode: the inline form with which an owner creates a team member (no email, so no invitation). The owner's own
 * password confirms it, and their second factor when they have one. On success the one-time password goes to the parent,
 * which shows it once.
 */
export function CreateMemberForm({
  ownerHasTwoFactor,
  onCreated,
  onClose,
}: {
  ownerHasTwoFactor: boolean;
  onCreated: (member: TeamMember, oneTimePassword: string) => void;
  onClose: () => void;
}) {
  const { t } = useI18n();
  const { form, busy, fields, formError, run, idle } = useAuthForm(FIELDS, 'createMember');
  const second = useSecondFactor();
  const name = useRef<HTMLInputElement>(null);

  useEffect(() => name.current?.focus(), []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const done = await run(async () => {
      const created = await createTeamMember(
        {
          name: String(data.get('name') ?? ''),
          email: String(data.get('email') ?? ''),
          role: String(data.get('role') ?? '') as InvitedRole,
          password: String(data.get('password') ?? ''),
        },
        ownerHasTwoFactor ? second.factor() : undefined,
      );

      onCreated(created.member, created.oneTimePassword);
    });

    if (done) idle();
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      onSubmit={submit}
      data-testid="create-member-form"
      aria-labelledby="create-member-title"
      className="flex flex-col gap-4 rounded-md border border-primary-line bg-primary-soft p-4"
    >
      <h3 id="create-member-title" className="text-md font-bold text-ink">
        {t('institution.users.create.title')}
      </h3>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="create-member-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        ref={name}
        label={t('institution.users.create.name')}
        name="name"
        autoComplete="off"
        required
        data-testid="create-member-name"
        error={fields.name}
        errorTestId="create-member-name-error"
      />
      <Input
        label={t('institution.users.create.email')}
        help={t('institution.users.create.emailHelp')}
        name="email"
        type="email"
        autoComplete="off"
        autoCapitalize="none"
        spellCheck={false}
        required
        data-testid="create-member-email"
        error={fields.email}
        errorTestId="create-member-email-error"
      />
      <Select
        label={t('institution.users.create.role')}
        name="role"
        defaultValue="manager"
        data-testid="create-member-role"
        error={fields.role}
        errorTestId="create-member-role-error"
      >
        <option value="manager">{t('admin.roles.manager')}</option>
        <option value="owner">{t('admin.roles.owner')}</option>
      </Select>
      <PasswordField
        label={t('institution.users.create.password')}
        help={t('institution.users.create.passwordHelp')}
        name="password"
        autoComplete="current-password"
        data-testid="create-member-password"
        toggleTestId="create-member-password-toggle"
        error={fields.password}
        errorTestId="create-member-password-error"
      />
      {ownerHasTwoFactor ? (
        <SecondFactorField
          mode={second.mode}
          value={second.value}
          onChange={second.setValue}
          onSwap={second.swap}
          error={fields.code ?? fields.recovery_code}
          labels={{
            code: t('institution.users.create.code'),
            codeHelp: t('institution.users.resetCodeHelp'),
            recovery: t('institution.users.create.recovery'),
            recoveryHelp: t('institution.users.resetRecoveryHelp'),
          }}
          testIds={{
            code: 'create-member-code',
            codeError: 'create-member-code-error',
            recovery: 'create-member-recovery-code',
            recoveryError: 'create-member-recovery-code-error',
            toggle: 'create-member-recovery-toggle',
          }}
        />
      ) : null}

      <div className="flex flex-wrap gap-3">
        <Button type="submit" loading={busy} data-testid="create-member-submit">
          {t('institution.users.create.submit')}
        </Button>
        <Button variant="secondary" onClick={onClose} data-testid="create-member-close">
          {t('institution.users.create.cancel')}
        </Button>
      </div>
    </form>
  );
}
