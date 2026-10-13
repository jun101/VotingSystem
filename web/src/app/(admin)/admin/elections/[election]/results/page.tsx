import { notFound } from 'next/navigation';
import { ResultsPage } from '@/components/results/ResultsPage';
import { fetchElection, fetchElectionResults, fetchElectionTurnout } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A13: the results of one closed election. An election that does not exist, is not a UUID
 * or belongs to another institution is the same real 404; an election that is not closed gets
 * the "not available" card and no figure (FR-SEC-03).
 */
export default async function ResultsRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  const results = await fetchElectionResults(id);

  if (results === 'missing') notFound();
  if (!results) throw new Error('The results could not be read.');

  const ballots = results === 'unavailable' ? null : results.ballots;
  const turnout = ballots ? await fetchElectionTurnout(id) : null;
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
        'results',
        'publication',
      )}
    >
      <ResultsPage
        election={election}
        results={ballots}
        closedAt={results === 'unavailable' ? null : results.election.closed_at}
        turnout={turnout}
      />
    </I18nProvider>
  );
}
