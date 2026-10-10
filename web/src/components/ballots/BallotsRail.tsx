'use client';

import { Panel } from '@/components/elections/Panel';
import { Pill } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

/**
 * The right rail of the ballots page: beside the zone from 1600 px (340 px wide), under it as a
 * row below. In 06a it holds the one card that says what is to come (the party card and the
 * "to check" card arrive with their data in 06b and 06c).
 */
export function BallotsRail() {
  const { t } = useI18n();

  return (
    <aside
      data-testid="ballots-rail"
      aria-label={t('ballots.rail.label')}
      className="flex w-full flex-wrap gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col"
    >
      <Panel
        tone="cover"
        icon="flag"
        title={t('ballots.rail.title')}
        id="ballots-rail-title"
        testId="ballots-rail-soon"
        className="flex-[1_1_20rem] 2xl:flex-none"
      >
        <p className="text-base text-ink-soft">{t('ballots.rail.text')}</p>
        <span className="w-fit">
          <Pill tone="neutral">{t('ballots.rail.soon')}</Pill>
        </span>
      </Panel>
    </aside>
  );
}
