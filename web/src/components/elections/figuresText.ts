import type { Election } from '@/lib/api/elections';
import { pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { MessageKey, MessageParams } from '@/lib/i18n/messages';

type T = (key: MessageKey, params?: MessageParams) => string;

/** `Aucun poste · aucun électeur`, `4 postes · 300 électeurs`. */
export function figuresText(
  election: Pick<Election, 'ballots_count' | 'voters_count'>,
  locale: Locale,
  t: T,
): string {
  const ballots =
    election.ballots_count === 0
      ? t('elections.card.ballots.zero')
      : t(`elections.card.ballots.${pluralForm(locale, election.ballots_count)}`, {
          count: election.ballots_count,
        });
  const voters =
    election.voters_count === 0
      ? t('elections.card.voters.zero')
      : t(`elections.card.voters.${pluralForm(locale, election.voters_count)}`, {
          count: election.voters_count,
        });

  return t('elections.card.figures', { ballots, voters });
}
