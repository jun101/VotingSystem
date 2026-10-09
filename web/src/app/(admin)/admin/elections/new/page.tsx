import { ElectionForm } from '@/components/elections/ElectionForm';
import { fetchInstitution } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** Screen A04: a new election, starting from the time zone and the language of the institution. */
export default async function NewElectionRoute() {
  const institution = await fetchInstitution();

  if (!institution) throw new Error('The institution could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'elections')}
    >
      <ElectionForm defaults={{ timezone: institution.timezone, language: institution.language }} />
    </I18nProvider>
  );
}
