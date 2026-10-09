import { Dashboard } from '@/components/admin/Dashboard';
import {
  fetchElections,
  fetchInstitution,
  fetchSession,
  fetchTeam,
  fetchTwoFactor,
} from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** The dashboard: the elections, the institution, its users (an owner only) and the two-factor state, read on the server. */
export default async function AdminPage() {
  const session = await fetchSession();
  const owner = session.status === 'signed-in' && session.user.role === 'owner';
  const [list, institution, team, twoFactor] = await Promise.all([
    fetchElections({}),
    fetchInstitution(),
    owner ? fetchTeam() : null,
    fetchTwoFactor(),
  ]);

  // The layout has judged the session; an API that does not answer is the error page.
  if (!list) throw new Error('The elections could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'elections')}
    >
      <Dashboard
        now={new Date().toISOString()}
        elections={list.items}
        counts={list.counts}
        institution={institution}
        team={team}
        twoFactor={twoFactor}
      />
    </I18nProvider>
  );
}
