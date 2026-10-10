'use client';

import Link from 'next/link';
import { Icon, type IconName } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Pill } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { useI18n } from '@/lib/i18n/client';
import { Panel, PANEL_ASIDE } from './Panel';

const STEPS = [
  { key: 'ballots', icon: 'flag', tone: 'bg-primary-soft text-primary' },
  { key: 'voters', icon: 'people', tone: 'bg-teal-soft text-teal' },
  { key: 'schedule', icon: 'lock', tone: 'bg-status-published-soft text-status-published' },
  { key: 'codes', icon: 'qr', tone: 'bg-warm-soft text-warm' },
] as const satisfies readonly { key: string; icon: IconName; tone: string }[];

/**
 * The four steps after the draft (ballots, voters, scheduling, codes). On the form they are a
 * reminder, in a list; on the summary page, where `soon` is set, they are a path of four coloured
 * circles joined by dotted lines, each saying it is not available yet (they open in the next
 * slices).
 */
export function NextSteps({
  soon = false,
  title,
  headerTestId,
  ballotsHref,
}: {
  soon?: boolean;
  title: string;
  headerTestId?: string;
  /** Where the first step is: when given, that step is a link and has no "not available" tag. */
  ballotsHref?: string;
}) {
  const { t } = useI18n();

  return (
    <Panel
      tone="steps"
      icon="flag"
      title={title}
      id="election-next-steps-title"
      testId="election-next-steps"
      headerTestId={headerTestId}
      aside={
        soon ? (
          <span className={PANEL_ASIDE}>
            {t('elections.summary.stepsCount', { done: 0, total: STEPS.length })}
          </span>
        ) : null
      }
    >
      <ol
        className={cx(
          soon
            ? 'grid grid-cols-1 gap-y-5 py-1 @[28rem]:grid-cols-2 @[44rem]:grid-cols-4'
            : 'flex flex-col gap-3',
        )}
      >
        {STEPS.map((step, index) =>
          soon ? (
            step.key === 'ballots' && ballotsHref ? (
              <li
                key={step.key}
                data-testid={`election-step-${index + 1}`}
                data-state="ready"
                className="path-step flex"
              >
                <Link
                  href={ballotsHref}
                  className={cx(
                    'group flex w-full flex-col items-center gap-2 rounded-lg px-3.5 text-center',
                    focusRing,
                  )}
                >
                  <span
                    aria-hidden="true"
                    className={cx(
                      'path-dot relative z-10 flex size-13.5 items-center justify-center rounded-full shadow-[0_0_0_6px_var(--color-surface)]',
                      step.tone,
                    )}
                  >
                    <Icon name={step.icon} size={26} />
                  </span>
                  <b className="font-display text-lg font-extrabold text-ink">
                    {t(`elections.next.${step.key}.title`)}
                  </b>
                  <span className="max-w-64 text-base text-ink-soft">
                    {t(`elections.next.${step.key}.text`)}
                  </span>
                  <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-xs font-bold text-primary-hover group-hover:bg-primary-line">
                    {t('elections.card.open')}
                    <Icon name="open" size={14} />
                  </span>
                </Link>
              </li>
            ) : (
              <li
                key={step.key}
                data-testid={`election-step-${index + 1}`}
                data-state="soon"
                className="path-step flex flex-col items-center gap-2 px-3.5 text-center"
              >
                <span
                  aria-hidden="true"
                  className={cx(
                    'path-dot relative z-10 flex size-13.5 items-center justify-center rounded-full shadow-[0_0_0_6px_var(--color-surface)]',
                    step.tone,
                  )}
                >
                  <Icon name={step.icon} size={26} />
                </span>
                <b className="font-display text-lg font-extrabold text-ink">
                  {t(`elections.next.${step.key}.title`)}
                </b>
                <span className="max-w-64 text-base text-ink-soft">
                  {t(`elections.next.${step.key}.text`)}
                </span>
                <Pill tone="neutral">{t('elections.summary.soon')}</Pill>
              </li>
            )
          ) : (
            <li key={step.key} className="flex items-start gap-3">
              <span
                aria-hidden="true"
                className={cx(
                  'flex size-9 shrink-0 items-center justify-center rounded-full',
                  step.tone,
                )}
              >
                <Icon name={step.icon} size={19} />
              </span>
              <span className="min-w-0 flex-1 text-base text-ink-soft">
                <strong className="font-semibold text-ink">
                  {t(`elections.next.${step.key}.title`)}
                </strong>
                {t('elections.next.separator')}
                {t(`elections.next.${step.key}.text`)}
              </span>
            </li>
          ),
        )}
      </ol>
      {soon ? null : <p className="text-sm text-ink-soft">{t('elections.next.footer')}</p>}
    </Panel>
  );
}
