import Link from 'next/link';
import { PageShell } from '@/components/ui';
import { getI18n } from '@/lib/i18n/server';

export default async function NotFound() {
  const { t } = await getI18n();

  return (
    <PageShell productName={t('app.name')}>
      <h1 className="text-3xl text-ink">{t('notFound.title')}</h1>
      <p className="text-md text-ink-soft">{t('notFound.text')}</p>
      <p>
        <Link href="/" className="font-semibold text-primary underline">
          {t('notFound.home')}
        </Link>
      </p>
    </PageShell>
  );
}
