'use client';

import { useI18n } from '@/lib/i18n/client';
import { ChangePasswordCard } from './ChangePasswordCard';

/** The only screen of a user who holds a one-time password (school mode): choose their own, then go on. */
export function PasswordChangeRequired({ twoFactorEnabled }: { twoFactorEnabled: boolean }) {
  const { t } = useI18n();

  return (
    <main className="mx-auto grid max-w-lg gap-6 px-4 py-10" data-testid="password-change-required">
      <header className="grid gap-2">
        <p className="text-sm font-medium text-primary">{t('account.passwordRequired.kicker')}</p>
        <h1 className="text-2xl font-semibold text-ink">{t('account.passwordRequired.title')}</h1>
        <p className="text-base text-ink-soft">{t('account.passwordRequired.text')}</p>
      </header>
      <ChangePasswordCard twoFactorEnabled={twoFactorEnabled} required />
    </main>
  );
}
