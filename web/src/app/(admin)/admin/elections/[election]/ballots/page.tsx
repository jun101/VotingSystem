import { notFound } from 'next/navigation';
import { BallotsPage } from '@/components/ballots/BallotsPage';
import { fetchElection, fetchElectionBallots, fetchElectionParties } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A06: the positions of one election. An election that does not exist, that is not a UUID
 * or that belongs to another institution is the same real 404.
 */
export default async function BallotsRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  const ballots = await fetchElectionBallots(id);

  if (!ballots) throw new Error('The ballots could not be read.');

  const parties = await fetchElectionParties(id);

  if (!parties) throw new Error('The parties could not be read.');

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
        'ballots',
        'parties',
        'candidates',
      )}
    >
      <BallotsPage election={election} initial={ballots} initialParties={parties} />
    </I18nProvider>
  );
}
