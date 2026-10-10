import { notFound } from 'next/navigation';
import { VotersPage } from '@/components/voters/VotersPage';
import { filtersOf } from '@/components/voters/voterQuery';
import { pageCount } from '@/components/voters/voterText';
import { fetchElection, fetchElectionGroups, fetchElectionVoters } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A08: the voters of one election, with the page, the search and the group of the
 * address. An election that does not exist, that is not a UUID or that belongs to another
 * institution is the same real 404.
 */
export default async function VotersRoute({
  params,
  searchParams,
}: {
  params: Promise<{ election: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  let filters = filtersOf(await searchParams);
  let voters = await fetchElectionVoters(id, filters);

  if (!voters) throw new Error('The voters could not be read.');

  // A page past the end (a voter was deleted since the link was made): the last one.
  const last = pageCount(voters.total);

  if (filters.page > last) {
    filters = { ...filters, page: last };
    voters = await fetchElectionVoters(id, filters);

    if (!voters) throw new Error('The voters could not be read.');
  }

  const groups = await fetchElectionGroups(id);

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
        'groups',
      )}
    >
      <VotersPage
        election={election}
        initialFilters={filters}
        initial={voters}
        initialGroups={groups}
      />
    </I18nProvider>
  );
}
