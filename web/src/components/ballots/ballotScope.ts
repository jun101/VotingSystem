import type { MessageKey, MessageParams } from '@/lib/i18n/messages';
import type { Locale } from '@/lib/i18n/locale';
import { votersText } from '@/components/voters/voterText';

type T = (key: MessageKey, params?: MessageParams) => string;

/** Who a position is for: every voter, or the voters of some groups. */
export type BallotScope = 'general' | 'group';

/** The few fields of a ballot these helpers read (the API's own names). */
export type ScopedBallot = {
  title: string;
  scope: BallotScope;
  groups: { id: string; name: string }[];
  eligible_voters: number;
  warnings: string[];
};

/** A position covering fewer voters than this gets the `few_voters` warning (FR-BAL-06). */
export const FEW_VOTERS = 5;

/** The groups chosen after a tap on one chip: added when absent, removed when present. */
export function toggleGroup(selected: readonly string[], group: string): string[] {
  return selected.includes(group) ? selected.filter((id) => id !== group) : [...selected, group];
}

/**
 * The scope line of a card, with the number of voters in words: `Tous les électeurs : 9 électeurs`
 * for a general position, `Groupes seulement : 6 électeurs` for a group one.
 */
export function scopeText(ballot: ScopedBallot, locale: Locale, t: T): string {
  const voters = votersText(ballot.eligible_voters, locale, t);

  return t(ballot.scope === 'group' ? 'ballots.scope.group' : 'ballots.scope.general', { voters });
}

/** The names of the groups of a position, as one line: `4e année, 5e année`. */
export function groupNames(ballot: ScopedBallot): string {
  return ballot.groups.map((group) => group.name).join(', ');
}

/** The message of the warning of a card, or null when there is none (no voter first, then few). */
export function warningText(ballot: ScopedBallot, locale: Locale, t: T): string | null {
  if (ballot.warnings.includes('no_voters')) return t('ballots.card.noVoters');

  if (ballot.warnings.includes('few_voters')) {
    return t('ballots.card.fewVoters', {
      voters: votersText(ballot.eligible_voters, locale, t),
      limit: FEW_VOTERS,
    });
  }

  return null;
}

/**
 * The titles of the positions that cover a group, in the order of the list: the ones a merge of
 * this group moves to the receiving group.
 */
export function ballotsCovering(
  ballots: readonly { title: string; groups: { id: string }[] }[],
  group: string,
): string[] {
  return ballots
    .filter((ballot) => ballot.groups.some((covered) => covered.id === group))
    .map((ballot) => ballot.title);
}
