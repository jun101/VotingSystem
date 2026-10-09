'use client';

import Link from 'next/link';
import { Icon, type IconName } from '@/components/admin/Icon';
import { Button } from '@/components/ui';
import { focusRing } from '@/components/admin/classes';
import { LiveDot } from '@/components/motion';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';
import { figuresText } from './figuresText';
import { rangeText } from './rangeText';
import { COVER_ICONS, STATUS_ICONS } from './statusIcon';

/** The text colour of the badge on the cover, by status (the chip colours of the design). */
const BADGE_TEXT: Record<ElectionStatus, string> = {
  draft: 'text-status-draft',
  scheduled: 'text-status-scheduled',
  open: 'text-status-open',
  closed: 'text-ink-soft',
  published: 'text-status-published',
  archived: 'text-ink-soft',
};

/** A fact tile: an icon, a figure and its label. */
function Fact({
  name,
  icon,
  value,
  label,
  position,
}: {
  name: 'positions' | 'voters' | 'ballots';
  icon: IconName;
  value: number;
  label: string;
  position: number;
}) {
  return (
    <div
      data-testid={`election-fact-${name}-${position}`}
      className="flex min-w-0 flex-col gap-0.5 rounded-md bg-canvas px-2.5 py-2.5"
    >
      <span className="text-primary">
        <Icon name={icon} size={18} />
      </span>
      <b
        data-testid={`election-fact-${name}-${position}-value`}
        className="font-display text-[20px] leading-6 font-extrabold text-ink"
      >
        {value}
      </b>
      <span className="text-xs font-medium break-words text-ink-soft">{label}</span>
    </div>
  );
}

/**
 * One election as a card: a 120 px gradient cover (its colours follow the status; the poster
 * fills it when there is one) with a large faint mark of the status and the status badge, then
 * the title, the dates, three fact tiles (positions, voters, ballots) and the actions with
 * their icons. It lifts on hover. The figures are also written in one line for screen readers.
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
      className="lift @container flex min-w-0 flex-col overflow-hidden rounded-lg bg-surface shadow-2"
    >
      <div
        data-testid={`election-band-${position}`}
        data-cover={election.status}
        className="cover-sweep relative flex h-[120px] shrink-0 items-end px-[18px] py-3.5"
      >
        {election.cover ? (
          // The cover is the 480 px file: a plain image with its size set.
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={election.cover.sm}
            alt=""
            width={480}
            height={120}
            data-testid={`election-cover-${position}`}
            className="absolute inset-0 size-full object-cover"
          />
        ) : (
          <span
            aria-hidden="true"
            className="absolute -top-[18px] -right-3.5 rotate-[-12deg] text-glass"
          >
            <Icon name={COVER_ICONS[election.status]} size={150} />
          </span>
        )}
        <span
          data-testid={`election-badge-${position}`}
          className={`relative z-10 inline-flex h-[30px] items-center gap-2 rounded-full bg-surface/95 px-3.5 text-sm font-bold ${BADGE_TEXT[election.status]}`}
        >
          {election.status === 'open' ? (
            <LiveDot />
          ) : (
            <Icon name={STATUS_ICONS[election.status]} size={16} />
          )}
          <span data-status={election.status} data-testid={`election-status-${position}`}>
            {t(`elections.status.${election.status}`)}
          </span>
        </span>
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-3.5 px-5 pt-[18px] pb-4">
        <h2
          data-testid={`election-title-${position}`}
          className="line-clamp-2 font-display text-[20px] leading-tight font-extrabold break-words text-ink"
        >
          {election.title}
        </h2>
        <p className="flex items-center gap-2.5 text-base text-ink-soft">
          <span className="text-primary">
            <Icon name="calendar" size={20} />
          </span>
          <span data-testid={`election-dates-${position}`}>{rangeText(election, locale, t)}</span>
        </p>

        <p className="sr-only">{figuresText(election, locale, t)}</p>
        <div aria-hidden="true" className="grid grid-cols-3 gap-2">
          <Fact
            name="positions"
            icon="flag"
            value={election.ballots_count}
            label={t('elections.card.facts.positions')}
            position={position}
          />
          <Fact
            name="voters"
            icon="people"
            value={election.voters_count}
            label={t('elections.card.facts.voters')}
            position={position}
          />
          {/* No vote count is ever shown before an election is closed (rule 6). */}
          <Fact
            name="ballots"
            icon="ballot"
            value={0}
            label={t('elections.card.facts.ballots')}
            position={position}
          />
        </div>

        <div className="mt-auto flex gap-1.5 border-t border-line-soft pt-3">
          <Link
            href={`/admin/elections/${election.id}`}
            aria-label={t(draft ? 'elections.card.continueLabel' : 'elections.card.openLabel', {
              title: election.title,
            })}
            data-testid={`election-open-${position}`}
            className={`ui-control inline-flex min-h-14 min-w-0 flex-1 items-center justify-center rounded-full px-1 font-medium whitespace-nowrap text-primary-hover hover:bg-primary-soft @[22rem]:min-h-11 ${focusRing}`}
          >
            <span className="inline-flex flex-col items-center gap-0.5 text-sm @[22rem]:flex-row @[22rem]:gap-2 @[22rem]:text-base">
              <Icon name={draft ? 'edit' : 'open'} size={19} />
              {t(draft ? 'elections.card.continue' : 'elections.card.open')}
            </span>
          </Link>
          <Button
            variant="quiet"
            disabled={busy}
            aria-label={t('elections.card.duplicateLabel', { title: election.title })}
            onClick={() => onDuplicate(election)}
            data-testid={`election-duplicate-${position}`}
            className="min-h-14! min-w-0 flex-1 px-1! whitespace-nowrap @[22rem]:min-h-11!"
          >
            <span className="inline-flex flex-col items-center gap-0.5 text-sm @[22rem]:flex-row @[22rem]:gap-2 @[22rem]:text-base">
              <Icon name="copy" size={19} />
              {t('elections.card.duplicate')}
            </span>
          </Button>
          {draft ? (
            <Button
              variant="quietDanger"
              disabled={busy}
              aria-label={t('elections.card.deleteLabel', { title: election.title })}
              onClick={() => onDelete(election)}
              data-testid={`election-delete-${position}`}
              className="min-h-14! min-w-0 flex-1 px-1! whitespace-nowrap @[22rem]:min-h-11!"
            >
              <span className="inline-flex flex-col items-center gap-0.5 text-sm @[22rem]:flex-row @[22rem]:gap-2 @[22rem]:text-base">
                <Icon name="trash" size={19} />
                {t('elections.card.delete')}
              </span>
            </Button>
          ) : null}
        </div>
      </div>
    </li>
  );
}
