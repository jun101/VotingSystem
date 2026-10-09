import { notFound, redirect } from 'next/navigation';
import { ElectionForm } from '@/components/elections/ElectionForm';
import { fetchElection, fetchInstitution } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** The same form as a new election, filled; only a draft can be edited, the others go back to the summary. */
export default async function EditElectionRoute({
  params,
}: {
  params: Promise<{ election: string }>;
}) {
  const { election: id } = await params;
  const [election, institution] = await Promise.all([fetchElection(id), fetchInstitution()]);

  if (election === 'missing') notFound();
  if (!election || !institution) throw new Error('The election could not be read.');
  if (election.status !== 'draft') redirect(`/admin/elections/${election.id}`);

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'elections')}
    >
      <ElectionForm
        election={election}
        defaults={{ timezone: institution.timezone, language: institution.language }}
      />
    </I18nProvider>
  );
}
