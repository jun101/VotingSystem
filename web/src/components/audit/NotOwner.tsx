'use client';

import Link from 'next/link';
import { linkSecondary } from '@/components/admin/classes';
import { Card } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

/** What a manager sees in place of the log: it is the owner's alone. They stay signed in. */
export function NotOwner() {
  const { t } = useI18n();

  return (
    <Card
      title={t('audit.notOwner.title')}
      role="status"
      data-testid="turned-away"
      className="max-w-2xl"
    >
      <div className="flex flex-col items-start gap-4">
        <p className="text-ink-soft">{t('audit.notOwner.text')}</p>
        <Link href="/admin" className={linkSecondary}>
          {t('audit.notOwner.back')}
        </Link>
      </div>
    </Card>
  );
}
