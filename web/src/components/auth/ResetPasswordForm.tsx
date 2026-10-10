'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Button, Notice } from '@/components/ui';
import { resetPassword } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { PasswordField } from './PasswordField';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['password'] as const;

/**
 * Choose a new password with the token of the link. A token that is unknown, used, replaced
 * or expired (422 on `token`, or 410) is shown as an invalid link, with a way to ask for a
 * new one. `token` is null when the address has none.
 */
export function ResetPasswordForm({ token }: { token: string | null }) {
  const { t } = useI18n();
  const router = useRouter();
  const [invalid, setInvalid] = useState(token === null);
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const done = await run(
      () => resetPassword({ token: token ?? '', password: String(data.get('password') ?? '') }),
      (error) => {
        const gone = error.code === 'expired' || (error.fields.token?.length ?? 0) > 0;

        if (gone) setInvalid(true);

        return gone;
      },
    );

    if (done) router.push('/login?reset=1');
  }

  if (invalid) {
    return (
      <div className="flex flex-col gap-5">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.reset.invalidTitle')}</h1>
        <Notice tone="danger" role="alert" data-testid="reset-invalid">
          <p>{t('auth.reset.invalid')}</p>
          <p className="mt-2">
            <Link href="/forgot-password" className="font-semibold underline">
              {t('auth.reset.request')}
            </Link>
          </p>
        </Notice>
      </div>
    );
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      data-testid="reset-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.reset.title')}</h1>
        <p className="text-ink-soft">{t('auth.reset.lede')}</p>
      </div>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      <PasswordField
        label={t('auth.reset.password')}
        name="password"
        autoComplete="new-password"
        required
        help={t('auth.reset.passwordHelp')}
        data-testid="reset-password"
        toggleTestId="reset-password-toggle"
        error={fields.password}
        errorTestId="field-error-password"
      />

      <Button type="submit" shimmer loading={busy} data-testid="reset-submit">
        {t('auth.reset.submit')}
      </Button>
    </form>
  );
}
