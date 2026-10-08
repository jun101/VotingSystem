'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { twoFactorChallenge } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['code', 'recovery_code'] as const;

/**
 * The second step of signing in, on the sign-in page itself: the 6-digit code of the
 * authenticator application, or one recovery code. A wrong code is told under the field. A
 * 401 (the pending sign-in ended: five minutes, five wrong codes) or a 403 (the institution was
 * suspended meanwhile) sends the person back to the password step, which says why.
 */
export function TwoFactorChallengeForm({
  onCancel,
  onEnded,
}: {
  onCancel: () => void;
  onEnded: (reason: 'expired' | 'suspended') => void;
}) {
  const { t } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS, 'challenge');
  const [recovery, setRecovery] = useState(false);
  const [blank, setBlank] = useState(false);
  const field = useRef<HTMLInputElement>(null);

  // The step opens on the field to fill, and so does each swap of the field.
  useEffect(() => field.current?.focus(), [recovery]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const value = String(
      new FormData(event.currentTarget).get(recovery ? 'recovery_code' : 'code') ?? '',
    );

    // The API has one "missing" answer (`code: required`), which would be read under the
    // wrong field in recovery mode: nothing is sent for an empty field.
    if (value.trim() === '') {
      setBlank(true);
      window.setTimeout(() => field.current?.focus(), 0);

      return;
    }

    setBlank(false);

    const done = await run(
      () => twoFactorChallenge(recovery ? { recovery_code: value } : { code: value }),
      (error) => {
        if (error.status === 401) onEnded('expired');
        else if (error.code === 'institution_suspended') onEnded('suspended');
        else return false;

        return true;
      },
    );

    if (done) {
      // The page is rendered again, so <html lang> takes the language stored for this user.
      router.push('/admin');
      router.refresh();
    }
  }

  const name = recovery ? 'recovery_code' : 'code';
  const error = blank ? t(`validation.challenge.${name}.required`) : fields[name];

  return (
    <form
      ref={form}
      method="post"
      noValidate
      data-testid="challenge-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.challenge.title')}</h1>
        <p className="text-ink-soft">
          {t(recovery ? 'auth.challenge.recoveryLede' : 'auth.challenge.lede')}
        </p>
      </div>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      {recovery ? (
        <Input
          key="recovery"
          ref={field}
          label={t('auth.challenge.recoveryCode')}
          name="recovery_code"
          type="text"
          autoComplete="off"
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          data-testid="challenge-recovery-code"
          error={error}
          errorTestId="challenge-recovery-code-error"
        />
      ) : (
        <Input
          key="code"
          ref={field}
          label={t('auth.challenge.code')}
          name="code"
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          spellCheck={false}
          data-testid="challenge-code"
          error={error}
          errorTestId="challenge-code-error"
        />
      )}

      <Button type="submit" variant="accent" loading={busy} data-testid="challenge-submit">
        {t('auth.challenge.submit')}
      </Button>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <button
          type="button"
          data-testid="challenge-recovery-toggle"
          onClick={() => {
            setRecovery((value) => !value);
            setBlank(false);
          }}
          className="ui-control min-h-11 text-start text-base font-semibold text-primary underline"
        >
          {t(recovery ? 'auth.challenge.useCode' : 'auth.challenge.useRecovery')}
        </button>
        <button
          type="button"
          data-testid="challenge-cancel"
          onClick={onCancel}
          className="ui-control min-h-11 text-base font-semibold text-ink-soft underline"
        >
          {t('auth.challenge.cancel')}
        </button>
      </div>
    </form>
  );
}
