import { headers } from 'next/headers';
import { Hero, HeroPill, Card, PageShell, Pill } from '@/components/ui';
import { LiveDot, Reveal } from '@/components/motion';
import { fetchHealthCached } from '@/lib/api/health';
import { formatDateTime } from '@/lib/format/dateTime';
import { getI18n } from '@/lib/i18n/server';

// Read at each visit: the page shows the state of the system now.
export const dynamic = 'force-dynamic';

export default async function HomePage() {
  const { locale, t } = await getI18n();
  const forwardedFor = (await headers()).get('x-forwarded-for');
  const health = await fetchHealthCached({ forwardedFor });

  const rows = [
    { id: 'api', label: t('home.status.api'), state: health.api },
    { id: 'database', label: t('home.status.database'), state: health.database },
    { id: 'redis', label: t('home.status.redis'), state: health.redis },
  ] as const;

  const pill = {
    ok: { tone: 'teal', text: t('home.status.online') },
    down: { tone: 'danger', text: t('home.status.offline') },
    unknown: { tone: 'neutral', text: t('home.status.unknown') },
  } as const;

  return (
    <PageShell
      productName={t('app.name')}
      hero={
        <Hero
          data-testid="home-hero"
          live
          pill={
            <HeroPill>
              <LiveDot className="text-accent-light" />
              {t('home.hero.pill')}
            </HeroPill>
          }
          title={t('app.name')}
          accent={t('home.hero.accent')}
          lede={t('home.hero.lede')}
        />
      }
    >
      <Reveal>
        <Card title={t('home.status.title')}>
          <dl className="flex flex-col divide-y divide-line-soft">
            {rows.map((row) => (
              <div
                key={row.id}
                data-testid={`status-${row.id}`}
                data-state={row.state}
                className="flex items-center justify-between gap-3 py-3 first:pt-0"
              >
                <dt className="text-ink-soft">{row.label}</dt>
                <dd>
                  <Pill tone={pill[row.state].tone}>{pill[row.state].text}</Pill>
                </dd>
              </div>
            ))}

            <div className="flex items-center justify-between gap-3 py-3 last:pb-0">
              <dt className="text-ink-soft">{t('home.status.time')}</dt>
              <dd className="text-right font-semibold text-ink">
                {health.time ? (
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
      </Reveal>
    </PageShell>
  );
}
