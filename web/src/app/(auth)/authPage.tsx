import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AuthLayout } from '@/components/auth/AuthLayout';
import { fetchCurrentUser } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

/**
 * What the sign-in pages share: the split layout of screen A01, the texts the page's forms
 * need, and the rule that a person who is signed in is sent on to the admin area (unless
 * the page works for them, as the verification page does).
 */
export async function AuthPage({
  children,
  redirectSignedIn = true,
}: {
  children: ReactNode;
  redirectSignedIn?: boolean;
}) {
  if (redirectSignedIn && (await fetchCurrentUser())) redirect('/admin');

  const { locale, t } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'auth', 'errors', 'validation')}
    >
      <AuthLayout
        productName={t('app.name')}
        tagline={t('auth.panel.title')}
        points={[
          t('auth.panel.point1'),
          t('auth.panel.point2'),
          t('auth.panel.point3'),
          t('auth.panel.point4'),
        ]}
      >
        {children}
      </AuthLayout>
    </I18nProvider>
  );
}
