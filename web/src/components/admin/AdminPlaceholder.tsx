'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Reveal } from '@/components/motion';
import { Button, Card, Hero, Notice, PageShell, Pill } from '@/components/ui';
import { logout, resendVerification } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import type { CurrentUser } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';

/**
 * The page a signed-in person lands on until slice 03 builds the admin area: who they are,
 * their institution, the way out, and a reminder while the email is not verified.
 */
export function AdminPlaceholder({ user }: { user: CurrentUser }) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const [verified] = useState(user.email_verified);
  const [resent, setResent] = useState(false);
  const [resending, setResending] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  async function resend() {
    setResending(true);
    setProblem(null);

    try {
      await resendVerification();
      setResent(true);
    } catch (error) {
      setProblem(errorText(error instanceof ApiError ? error : new ApiError(0, 'unknown'), tIfAny));
    } finally {
      setResending(false);
    }
  }

  async function signOut() {
    setSigningOut(true);
    setProblem(null);

    try {
      await logout();
    } catch (error) {
      // Already signed out elsewhere: the way out is the same.
      if (!(error instanceof ApiError && error.code === 'unauthenticated')) {
        setProblem(
          errorText(error instanceof ApiError ? error : new ApiError(0, 'unknown'), tIfAny),
        );
        setSigningOut(false);

        return;
      }
    }

    router.push('/login');
    router.refresh();
  }

  return (
    <PageShell
      productName={t('app.name')}
      variant="admin"
      hero={
        <Hero
          compact
          data-testid="admin-welcome"
          title={t('admin.placeholder.welcome', { name: user.name })}
          lede={t('admin.placeholder.soon')}
        />
      }
    >
      <Reveal className="flex flex-col gap-6">
        {!verified ? (
          <Notice
            tone="warm"
            title={t('admin.placeholder.bannerTitle')}
            data-testid="verify-banner"
            actions={
              resent ? (
                <Pill tone="teal" role="status" data-testid="resend-done">
                  {t('admin.placeholder.resendDone')}
                </Pill>
              ) : (
                <Button
                  variant="secondary"
                  loading={resending}
                  onClick={resend}
                  data-testid="resend-button"
                >
                  {t('admin.placeholder.resend')}
                </Button>
              )
            }
          >
            {t('admin.placeholder.bannerText', { email: user.email })}
          </Notice>
        ) : null}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="form-error">
            {problem}
          </Notice>
        ) : null}

        <Card
          actions={
            <Button
              variant="secondary"
              loading={signingOut}
              onClick={signOut}
              data-testid="signout-button"
            >
              {t('admin.placeholder.signOut')}
            </Button>
          }
        >
          <p className="font-display text-xl font-bold text-ink" data-testid="admin-institution">
            {t('admin.placeholder.institution', { name: user.institution?.name ?? user.name })}
          </p>
        </Card>
      </Reveal>
    </PageShell>
  );
}
