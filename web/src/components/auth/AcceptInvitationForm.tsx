'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { Button, Input, Notice } from '@/components/ui';
import { acceptInvitation } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { PasswordField } from './PasswordField';
import { useAuthForm } from './useAuthForm';

const FIELDS = ['name', 'password'] as const;

type Outcome = 'invalid' | 'expired' | 'taken';

/**
 * Accept an invitation: the person chooses a name and a password and enters the admin area. The
 * page does not say who invited them (the API does not, to keep the link quiet). An invitation
 * that is unknown, used, cancelled or replaced (404), expired (410) or whose address has an
 * account since (409) is shown as a clear page with a way to sign in. `token` is null when the
 * address has none.
 */
export function AcceptInvitationForm({ token }: { token: string | null }) {
  const { t } = useI18n();
  const router = useRouter();
  const [outcome, setOutcome] = useState<Outcome | null>(token === null ? 'invalid' : null);
  const { form, busy, fields, formError, run } = useAuthForm(FIELDS);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);

    const done = await run(
      () =>
        acceptInvitation({
          token: token ?? '',
          name: String(data.get('name') ?? ''),
          password: String(data.get('password') ?? ''),
        }),
      (error) => {
        const gone =
          error.status === 404
            ? 'invalid'
            : error.status === 410
              ? 'expired'
              : error.code === 'email_taken'
                ? 'taken'
                : null;

        if (gone) setOutcome(gone);

        return gone !== null;
      },
    );

    if (done) {
      // The page is rendered again, so <html lang> takes the language stored for this user.
      router.push('/admin');
      router.refresh();
    }
  }

  if (outcome) {
    return (
      <div className="flex flex-col gap-5">
        <h1 className="text-2xl text-ink md:text-3xl">{t(`auth.accept.${outcome}Title`)}</h1>
        <Notice tone="danger" role="alert" data-testid={`accept-${outcome}`}>
          <p>{t(`auth.accept.${outcome}`)}</p>
          <p className="mt-2">
            <Link href="/login" className="font-semibold underline">
              {t('auth.accept.signIn')}
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
      data-testid="accept-form"
      onSubmit={submit}
      className="flex flex-col gap-5"
    >
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl text-ink md:text-3xl">{t('auth.accept.title')}</h1>
        <p className="text-ink-soft">{t('auth.accept.lede')}</p>
      </div>

      {formError ? (
        <Notice tone="danger" role="alert" tabIndex={-1} data-testid="form-error">
          {formError}
        </Notice>
      ) : null}

      <Input
        label={t('auth.accept.name')}
        name="name"
        type="text"
        autoComplete="name"
        required
        data-testid="accept-name"
        error={fields.name}
        errorTestId="accept-name-error"
      />
      <PasswordField
        label={t('auth.accept.password')}
        name="password"
        autoComplete="new-password"
        required
        help={t('auth.accept.passwordHelp')}
        data-testid="accept-password"
        toggleTestId="accept-password-toggle"
        error={fields.password}
        errorTestId="accept-password-error"
      />

      <Button type="submit" variant="accent" loading={busy} data-testid="accept-submit">
        {t('auth.accept.submit')}
      </Button>
    </form>
  );
}
