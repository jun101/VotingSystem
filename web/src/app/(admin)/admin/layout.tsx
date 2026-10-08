import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AdminShell } from '@/components/admin/AdminShell';
import { TurnedAway } from '@/components/admin/TurnedAway';
import { fetchInstitution, fetchSession } from '@/lib/api/server';
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

  // The menu shows the institution's logo in place of its initials.
  const institution = await fetchInstitution();

  return (
    <I18nProvider locale={locale} messages={messages}>
      <AdminShell user={session.user} logo={institution?.logo?.sm ?? null}>
        {children}
      </AdminShell>
    </I18nProvider>
  );
}
