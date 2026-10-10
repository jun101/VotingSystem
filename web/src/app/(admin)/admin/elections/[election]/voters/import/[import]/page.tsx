import { notFound } from 'next/navigation';
import { ImportCheck } from '@/components/voters/ImportCheck';
import { fetchElection, fetchVoterImport } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A10: the check and the import of a voter file. An election or an import that does not
 * exist, that is not a UUID or that belongs to another institution is the same real 404, and so
 * is an import that belongs to another election of the institution.
 */
export default async function ImportCheckRoute({
  params,
}: {
  params: Promise<{ election: string; import: string }>;
}) {
  const { election: electionId, import: importId } = await params;
  const [election, voterImport] = await Promise.all([
    fetchElection(electionId),
    fetchVoterImport(importId),
  ]);

  if (election === 'missing' || voterImport === 'missing') notFound();
  if (!election || !voterImport) throw new Error('The import could not be read.');
  if (voterImport.election !== election.id) notFound();

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
      <ImportCheck election={election} initial={voterImport} />
    </I18nProvider>
  );
}
