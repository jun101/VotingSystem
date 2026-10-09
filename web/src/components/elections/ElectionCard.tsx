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
 * One election as a dense card: the status and the dates, the title (two lines at most), the
 * figures, the cover as a small thumbnail when there is one, and the actions on one line.
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
      className="flex min-w-0 flex-col justify-between gap-3 rounded-lg border border-line bg-surface p-4"
    >
      <div className="flex items-start gap-3">
        {election.cover ? (
          // The thumbnail is the 480 px file: a plain image with its size set.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={election.cover.sm}
            alt=""
            width={64}
            height={64}
            data-testid={`election-cover-${position}`}
            className="size-16 shrink-0 rounded border border-line bg-canvas object-cover"
          />
        ) : null}
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <StatusPill status={election.status} data-testid={`election-status-${position}`} />
            <span data-testid={`election-dates-${position}`} className="text-sm text-ink-soft">
              {rangeText(election, locale, t)}
            </span>
          </div>
          <h2
            data-testid={`election-title-${position}`}
            className="line-clamp-2 font-display text-lg leading-snug font-bold break-words text-ink"
          >
            {election.title}
          </h2>
          <p className="text-sm text-ink-soft">{figuresText(election, locale, t)}</p>
        </div>
      </div>

      <div className="flex gap-2">
        <Link
          href={`/admin/elections/${election.id}`}
          aria-label={t(draft ? 'elections.card.continueLabel' : 'elections.card.openLabel', {
            title: election.title,
          })}
          data-testid={`election-open-${position}`}
          className={`ui-control inline-flex min-h-11 min-w-0 flex-1 items-center justify-center rounded border border-primary bg-primary px-2 text-sm font-semibold whitespace-nowrap text-surface hover:border-primary-hover hover:bg-primary-hover ${focusRing}`}
        >
          {t(draft ? 'elections.card.continue' : 'elections.card.open')}
        </Link>
        <Button
          variant="secondary"
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
            variant="danger"
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
    </li>
  );
}
