'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type CSSProperties, type FormEvent } from 'react';
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
  // The meter is a picture of the length, which is the one rule: 12 characters at least.
  const [length, setLength] = useState(0);
  const filled = length === 0 ? 0 : length < 12 ? 1 : length < 16 ? 2 : 3;

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
        onChange={(event) => setLength(event.target.value.length)}
        error={fields.password}
        errorTestId="field-error-password"
      />
      <div aria-hidden="true" data-testid="register-strength" className="-mt-2 flex gap-1.5">
        {[1, 2, 3].map((segment) => (
          <i
            key={segment}
            data-on={filled >= segment}
            style={
              {
                '--strength':
                  filled === 1
                    ? 'var(--color-warm)'
                    : filled === 2
                      ? 'var(--color-primary)'
                      : 'var(--color-teal)',
              } as CSSProperties
            }
            className="strength-seg h-1 flex-1 rounded-sm bg-line-soft"
          />
        ))}
      </div>

      <Button type="submit" shimmer loading={busy} data-testid="register-submit">
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
