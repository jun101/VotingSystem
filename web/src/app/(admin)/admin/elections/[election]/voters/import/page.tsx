import { notFound } from 'next/navigation';
import { ImportPage } from '@/components/voters/ImportPage';
import { fetchElection } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A09: the template and the file of the voter import. An election that does not exist,
 * that is not a UUID or that belongs to another institution is the same real 404.
 */
export default async function ImportRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

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
        'voterImport',
      )}
    >
      <ImportPage election={election} />
    </I18nProvider>
  );
}
