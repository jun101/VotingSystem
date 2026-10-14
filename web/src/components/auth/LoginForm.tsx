'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { login } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { PasswordField } from './PasswordField';
import { TwoFactorChallengeForm } from './TwoFactorChallengeForm';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['email', 'password'] as const;

/**
 * Sign in. `reset` is true after a password change: a short confirmation is shown.
 * `suspended` explains why the admin area sent the person back here (an ended session: no reason
 * is known). A sign-in refused for a suspension (403 `institution_suspended`) shows the reason the
 * platform gave, when the API gives one.
 */
export function LoginForm({
  reset,
  suspended = false,
  school = false,
}: {
  reset: boolean;
  suspended?: boolean;
  /** School mode: no sign-up and no email reset, so no link to them. */
  school?: boolean;
}) {
  const { t } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run, idle } = useAuthForm(FIELDS);
  // After the password, a person with two-factor authentication gets the code step on this same
  // page. The password is not kept: only the address, to fill the field again if they cancel.
  const [step, setStep] = useState<'password' | 'code'>('password');
  const [ended, setEnded] = useState<'expired' | null>(null);
  // Set when the API refused the sign-in for a suspension; `reason` is its text, or null.
  const [refusal, setRefusal] = useState<{ reason: string | null } | null>(null);
  const [email, setEmail] = useState('');
  const passwordField = useRef<HTMLInputElement>(null);
  // Goes up at each return from the code step (cancelled, expired or suspended).
  const [returns, setReturns] = useState(0);

  // Back on the password step: the email is already filled in, so the focus goes to the password.
  useEffect(() => {
    if (step === 'password' && returns > 0) passwordField.current?.focus();
  }, [step, returns]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const next = { needsCode: false, home: '/admin' };

    setEmail(String(data.get('email') ?? ''));
    setRefusal(null);

    const done = await run(
      async () => {
        const result = await login({
          email: String(data.get('email') ?? ''),
          password: String(data.get('password') ?? ''),
        });

        next.needsCode = 'two_factor_required' in result;

        if ('role' in result && result.role === 'platform_admin') next.home = '/platform';
      },
      (error) => {
        if (error.code !== 'institution_suspended') return false;

        setRefusal({ reason: error.reason });

        return true;
      },
    );

    if (!done) return;

    if (next.needsCode) {
      setEnded(null);
      setStep('code');
      idle();

      return;
    }

    // The page is rendered again, so <html lang> takes the language stored for this user.
    router.push(next.home);
    router.refresh();
  }

  if (step === 'code') {
    return (
      <TwoFactorChallengeForm
        onCancel={() => {
          setEnded(null);
          setStep('password');
          setReturns((count) => count + 1);
        }}
        onEnded={(reason, detail) => {
          if (reason === 'suspended') setRefusal({ reason: detail });
          else setEnded(reason);

          setStep('password');
          setReturns((count) => count + 1);
        }}
      />
    );
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

      {ended === 'expired' ? (
        <Notice tone="warm" role="status" data-testid="challenge-expired">
          {t('auth.challenge.expired')}
        </Notice>
      ) : null}

      {refusal?.reason ? (
        <Notice tone="warm" role="alert" data-testid="login-suspended">
          <p>{t('auth.login.suspendedReason')}</p>
          <p data-testid="login-suspended-reason" className="mt-1 font-semibold break-words">
            {refusal.reason}
          </p>
        </Notice>
      ) : suspended || refusal ? (
        <Notice tone="warm" role="alert" data-testid="login-suspended">
          {t('auth.login.suspended')}
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
        defaultValue={email}
        type="email"
        autoComplete="email"
        autoCapitalize="none"
        spellCheck={false}
        required
        data-testid="login-email"
        labelTestId="login-email-label"
        error={fields.email}
        errorTestId="field-error-email"
      />
      <PasswordField
        ref={passwordField}
        label={t('auth.login.password')}
        name="password"
        autoComplete="current-password"
        required
        data-testid="login-password"
        toggleTestId="login-password-toggle"
        error={fields.password}
        errorTestId="field-error-password"
      />

      {school ? null : (
        <Link
          href="/forgot-password"
          data-testid="login-forgot"
          className="w-fit text-base font-semibold text-primary underline"
        >
          {t('auth.login.forgot')}
        </Link>
      )}

      <Button type="submit" shimmer loading={busy} data-testid="login-submit">
        {t('auth.login.submit')}
      </Button>

      {school ? null : (
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
      )}
    </form>
  );
}
