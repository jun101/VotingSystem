import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AdminShell } from '@/components/admin/AdminShell';
import { TurnedAway } from '@/components/admin/TurnedAway';
import { fetchSession } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * The frame of every page of the admin area: the side menu and the top bar. It is also the
 * guard: nobody signed in goes to the sign-in page, a suspended institution too (with its
 * message), and a platform admin, whose own area comes later, is signed out with a message.
 */
export default async function AdminLayout({ children }: { children: ReactNode }) {
  const session = await fetchSession();

  if (session.status === 'suspended') redirect('/login?suspended=1');
  if (session.status === 'signed-out') redirect('/login');

  const { locale } = await getI18n();
  const messages = pick(getMessages(locale), 'app', 'admin', 'errors');

  if (session.user.role === 'platform_admin') {
    return (
      <I18nProvider locale={locale} messages={messages}>
        <TurnedAway />
      </I18nProvider>
    );
  }

  return (
    <I18nProvider locale={locale} messages={messages}>
      <AdminShell user={session.user}>{children}</AdminShell>
    </I18nProvider>
  );
}
