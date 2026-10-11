import { redirect } from 'next/navigation';
import { AuditPage } from '@/components/audit/AuditPage';
import { NotOwner } from '@/components/audit/NotOwner';
import { fetchAuditFirstPage, fetchElections, fetchSession, fetchTeam } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A15: the audit log, for an owner only. A manager gets the "not for you" card, not the
 * log (the API answers 403 to a manager anyway).
 */
export default async function AuditRoute() {
  const session = await fetchSession();

  if (session.status !== 'signed-in') redirect('/login');

  const { locale } = await getI18n();
  const messages = pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'audit');

  if (session.user.role !== 'owner') {
    return (
      <I18nProvider locale={locale} messages={messages}>
        <NotOwner />
      </I18nProvider>
    );
  }

  const [first, elections, team] = await Promise.all([
    fetchAuditFirstPage(),
    fetchElections({}),
    fetchTeam(),
  ]);

  if (!first) throw new Error('The audit log could not be read.');

  return (
    <I18nProvider locale={locale} messages={messages}>
      <AuditPage
        initial={first}
        elections={(elections?.items ?? []).map((election) => ({
          id: election.id,
          title: election.title,
        }))}
        people={(team?.items ?? []).map((member) => ({ id: member.id, name: member.name }))}
      />
    </I18nProvider>
  );
}
