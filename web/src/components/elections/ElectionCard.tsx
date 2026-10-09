'use client';

import Link from 'next/link';
import { Button } from '@/components/ui';
import { focusRing } from '@/components/admin/classes';
import type { Election } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';
import { figuresText } from './figuresText';
import { rangeText } from './rangeText';
import { StatusPill } from './StatusPill';

/**
 * One election as a card: a 76 px gradient cover (its colours follow the status; the poster
 * fills it when there is one), the status chip and the dates, the title (two lines at most),
 * the figures, and the actions on one line. It lifts on hover.
 */
export function ElectionCard({
  election,
  position,
  busy,
  onDuplicate,
  onDelete,
}: {
  election: Election;
  /** 1 for the first card of the list: the numbers in the test ids. */
  position: number;
  busy: boolean;
  onDuplicate: (election: Election) => void;
  onDelete: (election: Election) => void;
}) {
  const { t, locale } = useI18n();
  const draft = election.status === 'draft';

  return (
    <li
      data-testid={`election-card-${position}`}
      className="lift flex min-w-0 flex-col overflow-hidden rounded-lg bg-surface shadow-2"
    >
      <div
        data-testid={`election-band-${position}`}
        data-cover={election.status}
        className="cover-sweep relative h-[76px] shrink-0"
      >
        {election.cover ? (
          // The cover is the 480 px file: a plain image with its size set.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={election.cover.sm}
            alt=""
            width={480}
            height={76}
            data-testid={`election-cover-${position}`}
            className="absolute inset-0 size-full object-cover"
          />
        ) : null}
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-2 p-4 pb-3">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <StatusPill status={election.status} data-testid={`election-status-${position}`} />
          <span data-testid={`election-dates-${position}`} className="text-sm text-ink-soft">
            {rangeText(election, locale, t)}
          </span>
        </div>
        <h2
          data-testid={`election-title-${position}`}
          className="line-clamp-2 font-display text-lg leading-snug font-extrabold break-words text-ink"
        >
          {election.title}
        </h2>
        <p className="text-sm text-ink-soft">{figuresText(election, locale, t)}</p>

        <div className="mt-auto flex gap-1.5 border-t border-line-soft pt-2">
          <Link
            href={`/admin/elections/${election.id}`}
            aria-label={t(draft ? 'elections.card.continueLabel' : 'elections.card.openLabel', {
              title: election.title,
            })}
            data-testid={`election-open-${position}`}
            className={`ui-control inline-flex min-h-11 min-w-0 flex-1 items-center justify-center rounded-full px-2 text-sm font-medium whitespace-nowrap text-primary-hover hover:bg-primary-soft ${focusRing}`}
          >
            {t(draft ? 'elections.card.continue' : 'elections.card.open')}
          </Link>
          <Button
            variant="quiet"
            disabled={busy}
            aria-label={t('elections.card.duplicateLabel', { title: election.title })}
            onClick={() => onDuplicate(election)}
            data-testid={`election-duplicate-${position}`}
            className="min-w-0 flex-1 px-2 text-sm whitespace-nowrap"
          >
            {t('elections.card.duplicate')}
          </Button>
          {draft ? (
            <Button
              variant="quietDanger"
              disabled={busy}
              aria-label={t('elections.card.deleteLabel', { title: election.title })}
              onClick={() => onDelete(election)}
              data-testid={`election-delete-${position}`}
              className="min-w-0 flex-1 px-2 text-sm whitespace-nowrap"
            >
              {t('elections.card.delete')}
            </Button>
          ) : null}
        </div>
      </div>
    </li>
  );
}
