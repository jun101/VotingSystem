'use client';

import Link from 'next/link';
import { useState, type FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { forgotPassword } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['email'] as const;

/** Ask for a reset link. The confirmation is the same whether or not the address has an account. */
export function ForgotPasswordForm() {
  const { t } = useI18n();
  const [sent, setSent] = useState(false);
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    if (await run(() => forgotPassword(String(data.get('email') ?? '')))) setSent(true);
  }

  if (sent) {
    return (
      <div className="flex flex-col gap-5">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.forgot.sentTitle')}</h1>
        <Notice tone="teal" role="status" data-testid="forgot-sent">
          {t('auth.forgot.sent')}
        </Notice>
        <Link href="/login" className="w-fit text-base font-semibold text-primary underline">
          {t('auth.forgot.back')}
        </Link>
      </div>
    );
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      data-testid="forgot-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.forgot.title')}</h1>
        <p className="text-ink-soft">{t('auth.forgot.lede')}</p>
      </div>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        label={t('auth.forgot.email')}
        name="email"
        type="email"
        autoComplete="email"
        autoCapitalize="none"
        spellCheck={false}
        required
        data-testid="forgot-email"
        error={fields.email}
        errorTestId="field-error-email"
      />

      <Button type="submit" shimmer loading={busy} data-testid="forgot-submit">
        {t('auth.forgot.submit')}
      </Button>

      <Link href="/login" className="w-fit text-base font-semibold text-primary underline">
        {t('auth.forgot.back')}
      </Link>
    </form>
  );
}
