import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { VoteCandidate } from '@/lib/api/vote';
import { useI18n } from '@/lib/i18n/client';

/**
 * A candidate as a tall tile, the photo first (4:5), then the name, the party with its colour and
 * the slogan. A button: `aria-pressed` says whether it is chosen. The party's colour is data (the
 * institution picked it), the only colour not taken from the tokens.
 */
export function CandidateTile({
  candidate,
  index,
  selected,
  locked,
  onToggle,
}: {
  candidate: VoteCandidate;
  index: number;
  selected: boolean;
  /** The ballot is full and this one is not chosen. */
  locked: boolean;
  onToggle: () => void;
}) {
  const { t } = useI18n();
  const party = candidate.party;

  return (
    <button
      type="button"
      data-testid={`vote-candidate-${index}`}
      aria-pressed={selected}
      disabled={locked}
      onClick={onToggle}
      style={{ animationDelay: `${Math.min(index - 1, 8) * 120}ms` }}
      className={cx(
        'select-ring ui-control lift-sm cand-tile flex w-[calc(50%-0.375rem)] min-w-0 flex-col overflow-hidden rounded-lg bg-surface text-left shadow-2 md:w-64 lg:w-72',
        locked && 'opacity-60 saturate-50',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed',
      )}
    >
      <span className="relative block aspect-[4/5] w-full overflow-hidden bg-line-soft">
        {candidate.photo ? (
          // An already optimised picture of the media disk, decorative: the name sits under it.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={candidate.photo}
            alt=""
            loading="lazy"
            className="absolute inset-0 size-full object-cover"
          />
        ) : (
          <span
            aria-hidden="true"
            data-sex={candidate.sex === 'female' ? 'female' : 'male'}
            className="cand-avatar absolute inset-0 flex items-center justify-center"
          >
            <Icon name="user" size={72} />
          </span>
        )}
        {party ? (
          <span
            aria-hidden="true"
            className="absolute inset-x-0 bottom-0 h-1.5"
            style={{ backgroundColor: party.colour }}
          />
        ) : null}
        {selected ? (
          <span
            aria-hidden="true"
            className="cand-check absolute top-2 right-2 flex size-10 items-center justify-center rounded-full bg-accent text-deep shadow-2"
          >
            <Icon name="check" size={20} />
          </span>
        ) : null}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-1.5 p-2.5 md:p-3">
        <span className="font-display text-base leading-tight font-extrabold [overflow-wrap:break-word] break-words text-ink md:text-lg">
          {candidate.first_name} {candidate.last_name}
        </span>
        <span className="flex min-w-0 items-center gap-2 text-sm font-semibold text-ink-2">
          {party ? (
            <span
              aria-hidden="true"
              className="size-2.5 shrink-0 rounded-[3px]"
              style={{ backgroundColor: party.colour }}
            />
          ) : null}
          <span className="min-w-0 break-words">
            {party ? party.name : t('vote.ballot.independent')}
          </span>
        </span>
        {candidate.slogan ? (
          <span className="line-clamp-3 text-sm break-words text-ink-soft">
            {t('vote.ballot.quote', { text: candidate.slogan })}
          </span>
        ) : null}
        {selected ? <span className="sr-only">{t('vote.ballot.chosen')}</span> : null}
      </span>
    </button>
  );
}
