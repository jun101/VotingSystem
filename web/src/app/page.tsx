import { headers } from 'next/headers';
import { Card, PageShell, Pill } from '@/components/ui';
import { fetchHealth } from '@/lib/api/health';
import { formatDateTime } from '@/lib/format/dateTime';
import { getI18n } from '@/lib/i18n/server';

// Read at each visit: the page shows the state of the system now.
export const dynamic = 'force-dynamic';

export default async function HomePage() {
  const { locale, t } = await getI18n();
  const forwardedFor = (await headers()).get('x-forwarded-for');
  const health = await fetchHealth({ forwardedFor });

  const rows = [
    { id: 'api', label: t('home.status.api'), up: health.reachable },
    { id: 'database', label: t('home.status.database'), up: health.reachable && health.database },
    { id: 'redis', label: t('home.status.redis'), up: health.reachable && health.redis },
  ] as const;

  return (
    <PageShell productName={t('app.name')}>
      <h1 className="text-3xl text-ink">{t('app.name')}</h1>

      <Card title={t('home.status.title')}>
        <dl className="flex flex-col divide-y divide-line-soft">
          {rows.map((row) => (
            <div
              key={row.id}
              data-testid={`status-${row.id}`}
              data-state={row.up ? 'ok' : 'down'}
              className="flex items-center justify-between gap-3 py-3 first:pt-0"
            >
              <dt className="text-ink-soft">{row.label}</dt>
              <dd>
                <Pill tone={row.up ? 'teal' : 'danger'}>
                  {row.up ? t('home.status.online') : t('home.status.offline')}
                </Pill>
              </dd>
            </div>
          ))}

          <div className="flex items-center justify-between gap-3 py-3 last:pb-0">
            <dt className="text-ink-soft">{t('home.status.time')}</dt>
            <dd className="text-right font-semibold text-ink">
              {health.reachable ? (
                <time data-testid="status-time" dateTime={health.time}>
                  {formatDateTime(health.time, locale)}
                </time>
              ) : (
                <span>—</span>
              )}
            </dd>
          </div>
        </dl>
      </Card>
    </PageShell>
  );
}
