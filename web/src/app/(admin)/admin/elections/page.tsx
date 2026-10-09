import { ElectionsPage } from '@/components/elections/ElectionsPage';
import { ELECTION_STATUSES, type ElectionFilters, type ElectionStatus } from '@/lib/api/elections';
import { fetchElections } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

type Query = Promise<Record<string, string | string[] | undefined>>;

/** The filters of the address: a status that exists and a year of four digits; anything else is left out. */
function filtersOf(query: Record<string, string | string[] | undefined>): ElectionFilters {
  const status = typeof query.status === 'string' ? query.status : undefined;
  const year =
    typeof query.year === 'string' && /^\d{4}$/.test(query.year) ? Number(query.year) : undefined;

  return {
    ...(status && (ELECTION_STATUSES as readonly string[]).includes(status)
      ? { status: status as ElectionStatus }
      : {}),
    ...(year ? { year } : {}),
  };
}

/** Screen A03: the elections of the institution, with their filters in the address. */
export default async function ElectionsRoute({ searchParams }: { searchParams: Query }) {
  const filters = filtersOf(await searchParams);
  const list = await fetchElections(filters);

  // The layout has judged the session; an API that does not answer is the error page.
  if (!list) throw new Error('The elections could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'elections')}
    >
      <ElectionsPage list={list} filters={filters} />
    </I18nProvider>
  );
}
