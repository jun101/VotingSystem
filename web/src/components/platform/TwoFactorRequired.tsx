'use client';

import type { ReactNode } from 'react';
import { Notice } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

/**
 * What a platform admin sees when the API refuses `/platform` until two-factor is on: the reason and,
 * below it, the account's two-factor card (passed as children).
 */
export function TwoFactorRequired({ children }: { children: ReactNode }) {
  const { t } = useI18n();

  return (
    <main
      className="mx-auto grid max-w-3xl gap-6 px-4 py-10"
      data-testid="platform-two-factor-required"
    >
      <header className="grid gap-2">
        <p className="text-sm font-medium text-primary">{t('platform.twoFactorRequiredKicker')}</p>
        <h1 className="text-2xl font-semibold text-ink">{t('platform.twoFactorRequiredTitle')}</h1>
      </header>
      <Notice tone="warm" role="status">
        {t('platform.twoFactorRequiredBody')}
      </Notice>
      {children}
    </main>
  );
}
