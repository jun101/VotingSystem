import { notFound } from 'next/navigation';
import { ElectionSummary } from '@/components/elections/ElectionSummary';
import { fetchElection } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * The summary page of one election. An election that does not exist, that is not a UUID or that
 * belongs to another institution is the same real 404.
 */
export default async function ElectionRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'elections')}
    >
      <ElectionSummary election={election} />
    </I18nProvider>
  );
}
