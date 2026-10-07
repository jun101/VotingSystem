import { redirect } from 'next/navigation';
import { AdminPlaceholder } from '@/components/admin/AdminPlaceholder';
import { fetchCurrentUser } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** A placeholder until slice 03 builds the admin area. Needs a signed-in user. */
export default async function AdminPage() {
  const user = await fetchCurrentUser();

  if (!user) redirect('/login');

  const { locale } = await getI18n();

  return (
    <I18nProvider locale={locale} messages={pick(getMessages(locale), 'app', 'admin', 'errors')}>
      <AdminPlaceholder user={user} />
    </I18nProvider>
  );
}
