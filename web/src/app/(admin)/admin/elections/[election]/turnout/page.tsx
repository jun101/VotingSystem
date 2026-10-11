import { notFound } from 'next/navigation';
import { TurnoutPage } from '@/components/turnout/TurnoutPage';
import {
  fetchElection,
  fetchElectionGroups,
  fetchElectionTurnout,
  fetchElectionVoters,
} from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A12: the turnout of one election. An election that does not exist, that is not a UUID
 * or that belongs to another institution is the same real 404.
 */
export default async function TurnoutRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  const [turnout, voters, groups] = await Promise.all([
    fetchElectionTurnout(id),
    fetchElectionVoters(id, { page: 1, q: '', group: '', credential: '', email: '', voted: 'no' }),
    fetchElectionGroups(id),
  ]);

  if (!turnout) throw new Error('The turnout could not be read.');
  if (!voters) throw new Error('The voters could not be read.');
  if (!groups) throw new Error('The groups could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(
        getMessages(locale),
        'app',
        'admin',
        'errors',
        'validation',
        'elections',
        'voters',
        'turnout',
      )}
    >
      <TurnoutPage
        election={election}
        initialTurnout={turnout}
        initialVoters={voters}
        groups={groups}
      />
    </I18nProvider>
  );
}
