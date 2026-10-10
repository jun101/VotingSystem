'use client';

import { useRouter } from 'next/navigation';
import { useEffect } from 'react';
import { Card } from '@/components/ui';
import { logout } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';

/**
 * A platform admin has no institution, and their own area comes in slice 18: they are signed
 * out and sent to the sign-in page with a message, not shown an institution area.
 */
export function TurnedAway() {
  const { t } = useI18n();
  const router = useRouter();

  useEffect(() => {
    // Signed out already (the session ended elsewhere) is the same outcome.
    logout()
      .catch(() => undefined)
      .finally(() => {
        router.replace('/login?platform=1');
        router.refresh();
      });
  }, [router]);

  return (
    <main className="bg-backdrop flex min-h-screen items-center justify-center px-4">
      <Card
        title={t('admin.turnedAway.title')}
        role="status"
        data-testid="turned-away"
        className="card-rise w-full max-w-xl shadow-3"
      >
        <p className="text-ink-soft">{t('admin.turnedAway.text')}</p>
      </Card>
    </main>
  );
}
