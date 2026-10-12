import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AdminShell } from '@/components/admin/AdminShell';
import { fetchInstitution, fetchSession } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * The frame of every page of the admin area: the side menu and the top bar. It is also the
 * guard: nobody signed in goes to the sign-in page, a suspended institution too (with its
 * message), and a platform admin goes to their own screen, `/platform`.
 */
export default async function AdminLayout({ children }: { children: ReactNode }) {
  const session = await fetchSession();

  if (session.status === 'suspended') redirect('/login?suspended=1');
  if (session.status === 'signed-out') redirect('/login');

  // A platform admin goes to their own screen, unless acting as an owner (the session is then
  // the owner's, with `impersonating`).
  if (session.user.role === 'platform_admin' && !session.user.impersonating) redirect('/platform');

  const { locale } = await getI18n();
  const messages = pick(getMessages(locale), 'app', 'admin', 'errors');

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
