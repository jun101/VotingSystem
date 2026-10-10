import Link from 'next/link';
import type { ReactNode } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { LiveDot } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';

/** The text colour of the badge on the header band, by status (as on the voters page). */
const BADGE_TEXT: Record<ElectionStatus, string> = {
  draft: 'text-status-draft',
  scheduled: 'text-status-scheduled',
  open: 'text-status-open',
  closed: 'text-ink-soft',
  published: 'text-status-published',
  archived: 'text-ink-soft',
};

const CHIP =
  'inline-flex h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass pr-3.5 pl-2.5 text-base font-medium';

const GLASS_LINK =
  'ui-control inline-flex min-h-11 items-center gap-2 rounded-full border border-glass-line bg-glass pr-4.5 pl-3.5 text-md font-semibold text-surface hover:bg-glass-line max-md:w-full max-md:justify-center ' +
  focusRing;

const STEPS = ['template', 'file', 'check', 'done'] as const;

/** The four steps of the import; `current` is the active one (1 to 4). */
export function Stepper({ current }: { current: number }) {
  const { t } = useI18n();

  return (
    <ol
      aria-label={t('voterImport.steps.label')}
      className="m-0 flex list-none flex-wrap items-center gap-2.5 p-0"
    >
      {STEPS.map((step, index) => {
        const n = index + 1;
        const done = n < current;
        const active = n === current;

        return (
          <li key={step} className="contents">
            <span
              data-testid={`import-step-${n}`}
              aria-current={active ? 'step' : undefined}
              className={cx(
                'flex items-center gap-2.5 text-md',
                active ? 'font-bold text-ink' : 'font-medium text-ink-soft',
              )}
            >
              <span
                aria-hidden="true"
                className={cx(
                  'flex size-8 items-center justify-center rounded-full font-display text-base font-extrabold',
                  active && 'bg-primary text-surface shadow-button',
                  done && 'bg-teal-soft text-teal-ink',
                  !active && !done && 'bg-primary-soft text-primary-hover',
                )}
              >
                {done ? <Icon name="check" size={16} /> : n}
              </span>
              <span className={cx(!active && 'max-md:sr-only')}>
                {t(`voterImport.steps.${step}`)}
              </span>
            </span>
            {n < STEPS.length ? (
              <span aria-hidden="true" className="h-0.5 w-10 bg-line max-md:hidden" />
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

export type HeroChip = { icon: IconName; label: string };

/**
 * The header band of the import pages (A09 and A10): the election's status badge, the title, a
 * few chips and the way back to the voters page.
 */
export function ImportHero({
  election,
  title,
  text,
  badge,
  chips,
  back,
}: {
  election: Election;
  title: string;
  text: string;
  /** The badge words of a draft election (the other statuses use their own name). */
  badge: string;
  chips: HeroChip[];
  back: { href: string; label: string };
}): ReactNode {
  const { t } = useI18n();

  return (
    <section
      data-testid="import-hero"
      data-status={election.status}
      aria-labelledby="import-title"
      className="election-hero cover-sweep relative flex min-h-32 flex-wrap items-end gap-x-6 gap-y-4 rounded-xl px-4 py-5 text-surface shadow-2 md:px-7.5 [@media(max-height:820px)]:min-h-24 [@media(max-height:820px)]:py-3.5"
    >
      <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
        <Icon name={COVER_ICONS[election.status]} size={210} />
      </span>
      <div className="relative z-10 min-w-0 flex-[1_1_22rem]">
        <span
          className={cx(
            'inline-flex min-h-7 items-center gap-2 rounded-full bg-surface/95 px-3.5 py-1 text-sm font-bold',
            BADGE_TEXT[election.status],
          )}
        >
          {election.status === 'open' ? (
            <LiveDot />
          ) : (
            <Icon name={STATUS_ICONS[election.status]} size={16} />
          )}
          <span data-status={election.status}>
            {election.status === 'draft' ? badge : t(`elections.status.${election.status}`)}
          </span>
        </span>
        <h2
          id="import-title"
          className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
        >
          {title}
        </h2>
        <p className="line-clamp-2 text-md break-words text-surface">{text}</p>
        <div className="mt-3 flex flex-wrap gap-2">
          {chips.map((chip) => (
            <span key={chip.label} className={CHIP}>
              <Icon name={chip.icon} size={18} />
              {chip.label}
            </span>
          ))}
        </div>
      </div>
      <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
        <Link href={back.href} data-testid="import-back" className={GLASS_LINK}>
          <Icon name="left" size={18} />
          {back.label}
        </Link>
      </div>
    </section>
  );
}
