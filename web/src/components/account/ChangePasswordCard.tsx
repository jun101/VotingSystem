'use client';

import { useState, type FormEvent } from 'react';
import { PasswordField } from '@/components/auth/PasswordField';
import { useAuthForm } from '@/components/auth/useAuthForm';
import { Button, Card, Notice } from '@/components/ui';
import { changePassword } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { loadPage } from '@/lib/navigation';
import { SecondFactorField, useSecondFactor } from './SecondFactorField';

const FIELDS = ['current_password', 'password', 'code', 'recovery_code'] as const;

/**
 * Change one's own password (docs/api/auth/POST-auth-change-password.md). With `required`, it is the screen of a user who
 * holds a one-time password: nothing else works until it is changed, and on success the page reloads into the admin area.
 */
export function ChangePasswordCard({
  twoFactorEnabled,
  required = false,
}: {
  twoFactorEnabled: boolean;
  required?: boolean;
}) {
  const { t } = useI18n();
  const { form, busy, fields, formError, run, idle } = useAuthForm(FIELDS, 'changePassword');
  const second = useSecondFactor();
  const [done, setDone] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const ok = await run(() =>
      changePassword(
        String(data.get('current_password') ?? ''),
        String(data.get('password') ?? ''),
        twoFactorEnabled ? second.factor() : undefined,
      ),
    );

    if (!ok) return;

    if (required) {
      // A full load: the layout reads the user again and the admin area opens.
      loadPage('/admin');

      return;
    }

    idle();
    form.current?.reset();
    second.reset();
    setDone(true);
  }

  return (
    <Card title={t('account.changePassword.title')} data-testid="change-password-card">
      <form ref={form} method="post" noValidate onSubmit={submit} className="flex flex-col gap-4">
        <p className="text-base text-ink-soft">{t('account.changePassword.text')}</p>

        {formError ? (
          <Notice tone="danger" role="alert" tabIndex={-1} data-testid="change-password-error">
            {formError}
          </Notice>
        ) : null}
        {done ? (
          <Notice tone="teal" role="status" data-testid="change-password-done">
            {t('account.changePassword.done')}
          </Notice>
        ) : null}

        <PasswordField
          label={t('account.changePassword.current')}
          name="current_password"
          autoComplete="current-password"
          data-testid="change-password-current"
          toggleTestId="change-password-current-toggle"
          error={fields.current_password}
          errorTestId="change-password-current-error"
        />
        <PasswordField
          label={t('account.changePassword.new')}
          help={t('account.changePassword.newHelp')}
          name="password"
          autoComplete="new-password"
          data-testid="change-password-new"
          toggleTestId="change-password-new-toggle"
          error={fields.password}
          errorTestId="change-password-new-error"
        />
        {twoFactorEnabled ? (
          <SecondFactorField
            mode={second.mode}
            value={second.value}
            onChange={second.setValue}
            onSwap={second.swap}
            error={fields.code ?? fields.recovery_code}
            labels={{
              code: t('account.changePassword.code'),
              codeHelp: t('institution.users.resetCodeHelp'),
              recovery: t('account.changePassword.recovery'),
              recoveryHelp: t('institution.users.resetRecoveryHelp'),
            }}
            testIds={{
              code: 'change-password-code',
              codeError: 'change-password-code-error',
              recovery: 'change-password-recovery-code',
              recoveryError: 'change-password-recovery-error',
              toggle: 'change-password-recovery-toggle',
            }}
          />
        ) : null}

        <Button type="submit" loading={busy} data-testid="change-password-submit">
          {t('account.changePassword.submit')}
        </Button>
      </form>
    </Card>
  );
}
