import { notFound } from 'next/navigation';
import { CodesPage } from '@/components/codes/CodesPage';
import { fetchElection, fetchElectionCredentials, fetchElectionFiles } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A11: the access codes of one election. An election that does not exist, that is not a
 * UUID or that belongs to another institution is the same real 404.
 */
export default async function CodesRoute({ params }: { params: Promise<{ election: string }> }) {
  const { election: id } = await params;
  const election = await fetchElection(id);

  if (election === 'missing') notFound();
  if (!election) throw new Error('The election could not be read.');

  const [state, files] = await Promise.all([fetchElectionCredentials(id), fetchElectionFiles(id)]);

  if (!state) throw new Error('The codes could not be read.');
  if (!files) throw new Error('The files could not be read.');

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
        'codes',
      )}
    >
      <CodesPage election={election} initialState={state} initialFiles={files} />
    </I18nProvider>
  );
}
