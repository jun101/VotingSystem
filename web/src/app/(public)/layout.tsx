import type { ReactNode } from 'react';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

/**
 * The public pages' frame: gives the error page of the segment (a client component, `error.tsx`)
 * its language and the texts it needs, which the pages themselves do not ship.
 */
export default async function PublicLayout({ children }: { children: ReactNode }) {
  const { locale } = await getI18n();

  return (
    <I18nProvider locale={locale} messages={pick(getMessages(locale), 'app', 'public')}>
      {children}
    </I18nProvider>
  );
}
