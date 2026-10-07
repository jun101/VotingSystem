'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import type { FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { login } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { PasswordField } from './PasswordField';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['email', 'password'] as const;

/**
 * Sign in. `reset` is true after a password change: a short confirmation is shown.
 * `suspended` and `platformAdmin` explain why the admin area sent the person back here.
 */
export function LoginForm({
  reset,
  suspended = false,
  platformAdmin = false,
}: {
  reset: boolean;
  suspended?: boolean;
  platformAdmin?: boolean;
}) {
  const { t } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const done = await run(() =>
      login({
        email: String(data.get('email') ?? ''),
        password: String(data.get('password') ?? ''),
      }),
    );

    if (done) router.push('/admin');
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      data-testid="login-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.login.title')}</h1>
        <p className="text-ink-soft">{t('auth.login.lede')}</p>
      </div>

      {reset ? (
        <Notice tone="teal" role="status" data-testid="login-notice">
          {t('auth.login.notice')}
        </Notice>
      ) : null}

      {suspended ? (
        <Notice tone="warm" role="alert" data-testid="login-suspended">
          {t('auth.login.suspended')}
        </Notice>
      ) : null}

      {platformAdmin ? (
        <Notice tone="warm" role="alert" data-testid="login-platform-admin">
          {t('auth.login.platformAdmin')}
        </Notice>
      ) : null}

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        label={t('auth.login.email')}
        name="email"
        type="email"
        autoComplete="email"
        autoCapitalize="none"
        spellCheck={false}
        required
        data-testid="login-email"
        error={fields.email}
        errorTestId="field-error-email"
      />
      <PasswordField
        label={t('auth.login.password')}
        name="password"
        autoComplete="current-password"
        required
        data-testid="login-password"
        toggleTestId="login-password-toggle"
        error={fields.password}
        errorTestId="field-error-password"
      />

      <Link
        href="/forgot-password"
        data-testid="login-forgot"
        className="w-fit text-base font-semibold text-primary underline"
      >
        {t('auth.login.forgot')}
      </Link>

      <Button type="submit" variant="accent" loading={busy} data-testid="login-submit">
        {t('auth.login.submit')}
      </Button>

      <p className="text-center text-base text-ink-soft">
        {t('auth.login.noAccount')}{' '}
        <Link
          href="/register"
          data-testid="login-register"
          className="font-semibold text-primary underline"
        >
          {t('auth.login.register')}
        </Link>
      </p>
    </form>
  );
}
