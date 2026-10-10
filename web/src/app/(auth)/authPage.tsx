import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AuthLayout } from '@/components/auth/AuthLayout';
import { fetchCurrentUser } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

/**
 * What the sign-in pages share: the sign-in card of screen A01, the texts the page's forms
 * need, and the rule that a person who is signed in is sent on to the admin area (unless
 * the page works for them, as the verification page does).
 */
export async function AuthPage({
  children,
  redirectSignedIn = true,
  steps = false,
}: {
  children: ReactNode;
  redirectSignedIn?: boolean;
  /** The register page: the panel lists the four steps instead of the illustration. */
  steps?: boolean;
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
        promise={t(steps ? 'auth.register.promise' : 'auth.panel.title')}
        steps={
          steps
            ? [
                t('auth.register.step1'),
                t('auth.register.step2'),
                t('auth.register.step3'),
                t('auth.register.step4'),
              ]
            : undefined
        }
      >
        {children}
      </AuthLayout>
    </I18nProvider>
  );
}
