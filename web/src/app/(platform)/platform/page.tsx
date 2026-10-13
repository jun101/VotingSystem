import { redirect } from 'next/navigation';
import { AccountPage } from '@/components/account/AccountPage';
import { PlatformPage } from '@/components/platform/PlatformPage';
import { TwoFactorRequired } from '@/components/platform/TwoFactorRequired';
import { fetchPlatformFirstPage, fetchSession, fetchTwoFactor } from '@/lib/api/server';
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

  const { locale } = await getI18n();

  // The platform rule (production): no `/platform` data before two-factor is on. The admin sets it up here.
  if (first === 'two-factor-required') {
    const twoFactor = await fetchTwoFactor();

    if (!twoFactor) throw new Error('The two-factor state could not be read.');

    return (
      <I18nProvider
        locale={locale}
        messages={pick(
          getMessages(locale),
          'app',
          'admin',
          'errors',
          'validation',
          'platform',
          'account',
          'auth',
        )}
      >
        <TwoFactorRequired>
          <AccountPage twoFactor={twoFactor} />
        </TwoFactorRequired>
      </I18nProvider>
    );
  }

  if (!first) throw new Error('The institutions could not be read.');

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'platform')}
    >
      <PlatformPage initial={first} />
    </I18nProvider>
  );
}
