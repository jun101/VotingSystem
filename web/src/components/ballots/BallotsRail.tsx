'use client';

import type { Ballot } from '@/lib/api/ballots';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { BallotChecks } from './BallotChecks';
import { PartiesCard } from './PartiesCard';
import type { Check } from './candidateList';

/**
 * The right rail of the ballots page: beside the zone from 1600 px (340 px wide), under it as a
 * row below. It holds the parties card and the "to check" card (the ballots to complete).
 */
export function BallotsRail({
  parties,
  checks,
  editable,
  onNewParty,
  onEditParty,
  onDeleteParty,
  onAddCandidate,
}: {
  parties: Party[];
  checks: Check[];
  editable: boolean;
  onNewParty: () => void;
  onEditParty: (party: Party) => void;
  onDeleteParty: (party: Party) => void;
  onAddCandidate: (ballot: Ballot) => void;
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
      <BallotChecks checks={checks} editable={editable} onAdd={onAddCandidate} />
    </aside>
  );
}
