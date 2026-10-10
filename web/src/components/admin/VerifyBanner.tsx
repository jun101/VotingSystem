'use client';

import { useState } from 'react';
import { Button, Notice, Pill } from '@/components/ui';
import { resendVerification } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { useAdminUser } from './AdminUser';

/** A reminder in the top bar while the user's email address is not verified (slice 02). */
export function VerifyBanner() {
  const user = useAdminUser();
  const { t, tIfAny } = useI18n();
  const [resent, setResent] = useState(false);
  const [resending, setResending] = useState(false);
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

  if (user.email_verified) return null;

  return (
    <div className="flex flex-col gap-3">
      <div
        data-testid="verify-banner"
        data-tone="warm"
        className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-warm bg-warm-softer px-4 py-2.5 text-warm-ink"
      >
        <div className="min-w-0 flex-1 basis-64">
          <p className="font-bold">{t('admin.banner.title')}</p>
          <p className="text-base">{t('admin.banner.text', { email: user.email })}</p>
        </div>
        {resent ? (
          <Pill tone="teal" role="status" data-testid="resend-done">
            {t('admin.banner.resendDone')}
          </Pill>
        ) : (
          <Button
            variant="secondary"
            loading={resending}
            onClick={resend}
            data-testid="resend-button"
          >
            {t('admin.banner.resend')}
          </Button>
        )}
      </div>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="form-error">
          {problem}
        </Notice>
      ) : null}
    </div>
  );
}
