import { redirect } from 'next/navigation';
import { PlatformPage } from '@/components/platform/PlatformPage';
import { fetchPlatformFirstPage, fetchSession } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/**
 * Screen A16: the institutions of the platform, for the platform admin alone. Nobody signed in
 * goes to the sign-in page, an owner or a manager to their own area.
 */
export default async function PlatformRoute() {
  const session = await fetchSession();

  if (session.status !== 'signed-in') redirect('/login');
  if (session.user.role !== 'platform_admin') redirect('/admin');

  const first = await fetchPlatformFirstPage();

  if (!first) throw new Error('The institutions could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'platform')}
    >
      <PlatformPage initial={first} />
    </I18nProvider>
  );
}
