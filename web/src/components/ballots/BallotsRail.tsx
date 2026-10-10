'use client';

import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { PartiesCard } from './PartiesCard';

/**
 * The right rail of the ballots page: beside the zone from 1600 px (340 px wide), under it as a
 * row below. It holds the parties card (the "to check" card arrives with the candidates, 06c).
 */
export function BallotsRail({
  parties,
  editable,
  onNewParty,
  onEditParty,
  onDeleteParty,
}: {
  parties: Party[];
  editable: boolean;
  onNewParty: () => void;
  onEditParty: (party: Party) => void;
  onDeleteParty: (party: Party) => void;
}) {
  const { t } = useI18n();

  return (
    <aside
      data-testid="ballots-rail"
      aria-label={t('ballots.rail.label')}
      className="flex w-full flex-wrap gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col"
    >
      <PartiesCard
        parties={parties}
        editable={editable}
        onNew={onNewParty}
        onEdit={onEditParty}
        onDelete={onDeleteParty}
      />
    </aside>
  );
}
