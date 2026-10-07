'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import type { FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { register } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { PasswordField } from './PasswordField';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['institution_name', 'name', 'email', 'password'] as const;

/** Screen A01: register an institution and its owner. */
export function RegisterForm() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const value = (name: string) => String(data.get(name) ?? '');

    const done = await run(() =>
      register({
        institution_name: value('institution_name'),
        name: value('name'),
        email: value('email'),
        password: value('password'),
        language: locale,
      }),
    );

    if (done) router.push('/admin');
  }

  return (
    <form
      ref={form}
      method="post"
      noValidate
      data-testid="register-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.register.title')}</h1>
        <p className="text-ink-soft">{t('auth.register.lede')}</p>
      </div>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        label={t('auth.register.institutionName')}
        name="institution_name"
        type="text"
        autoComplete="organization"
        required
        data-testid="register-institution-name"
        error={fields.institution_name}
        errorTestId="field-error-institution_name"
      />
      <Input
        label={t('auth.register.name')}
        name="name"
        type="text"
        autoComplete="name"
        required
        data-testid="register-name"
        error={fields.name}
        errorTestId="field-error-name"
      />
      <Input
        label={t('auth.register.email')}
        name="email"
        type="email"
        autoComplete="email"
        autoCapitalize="none"
        spellCheck={false}
        placeholder={t('auth.register.emailPlaceholder')}
        required
        data-testid="register-email"
        error={fields.email}
        errorTestId="field-error-email"
      />
      <PasswordField
        label={t('auth.register.password')}
        name="password"
        autoComplete="new-password"
        required
        help={t('auth.register.passwordHelp')}
        data-testid="register-password"
        toggleTestId="register-password-toggle"
        error={fields.password}
        errorTestId="field-error-password"
      />

      <Button type="submit" variant="accent" loading={busy} data-testid="register-submit">
        {t('auth.register.submit')}
      </Button>

      <p className="text-center text-base text-ink-soft">
        {t('auth.register.haveAccount')}{' '}
        <Link href="/login" className="font-semibold text-primary underline">
          {t('auth.register.signIn')}
        </Link>
      </p>
    </form>
  );
}
