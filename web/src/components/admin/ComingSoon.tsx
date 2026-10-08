'use client';

import Link from 'next/link';
import { Card } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { linkSecondary } from './classes';

/** What the pages of later slices show inside the shell, so no menu entry is a dead end. */
export function ComingSoon() {
  const { t } = useI18n();

  return (
    <Card title={t('admin.comingSoon.title')} data-testid="coming-soon" className="max-w-2xl">
      <div className="flex flex-col items-start gap-4">
        <p className="text-ink-soft">{t('admin.comingSoon.text')}</p>
        <Link href="/admin" className={linkSecondary}>
          {t('admin.comingSoon.back')}
        </Link>
      </div>
    </Card>
  );
}
