import { notFound } from 'next/navigation';
import { AccountPage } from '@/components/account/AccountPage';
import { fetchSession, fetchTwoFactor } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** "Mon compte": the settings of the signed-in person (two-factor authentication, for now). */
export default async function AccountRoute() {
  const session = await fetchSession();
  const twoFactor = await fetchTwoFactor();

  if (session.status !== 'signed-in') notFound();

  // Signed in, but the API did not answer: the error page, not a 404.
  if (!twoFactor) throw new Error('The two-factor state could not be read.');

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
        'account',
        'auth',
      )}
    >
      <AccountPage twoFactor={twoFactor} />
    </I18nProvider>
  );
}
