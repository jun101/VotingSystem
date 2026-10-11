'use client';

import { useEffect, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { LiveDot } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { Election } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';
import { remaining } from '@/components/vote/voteTime';
import { Panel } from './Panel';
import { momentText } from './scheduleText';

const BUTTON =
  'ui-control lift-sm inline-flex h-12 items-center gap-2 rounded-full pr-5.5 pl-4 text-md font-medium ' +
  focusRing;

/**
 * The live state of an open election: how long is left (a figure that only shows the time; what
 * the vote accepts is decided by the server), the end in the election's time zone, and the two
 * actions. The clock starts in an effect, so the server render and the first browser render agree.
 */
export function OpenCard({
  election,
  onClose,
  onExtend,
  className,
}: {
  election: Pick<Election, 'ends_at' | 'timezone'>;
  onClose: () => void;
  onExtend: () => void;
  className?: string;
}) {
  const { t, locale } = useI18n();
  const [now, setNow] = useState<number | null>(null);

  useEffect(() => {
    // Browser only: the first render shows dashes, like the voter's countdown.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setNow(Date.now());
    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, []);

  const end = new Date(election.ends_at);
  const left = now === null ? null : remaining(election.ends_at, now);
  const over = left !== null && left.days + left.hours + left.minutes + left.seconds === 0;
  const pad = (n: number) => String(n).padStart(2, '0');

  const units = [
    { key: 'days', value: left?.days, label: t('elections.open.days') },
    { key: 'hours', value: left?.hours, label: t('elections.open.hours') },
    { key: 'minutes', value: left?.minutes, label: t('elections.open.minutes') },
    { key: 'seconds', value: left?.seconds, label: t('elections.open.seconds') },
  ] as const;

  return (
    <Panel
      tone="facts"
      icon="flag"
      title={t('elections.open.title')}
      id="election-open-title"
      testId="election-open-card"
      headerTestId="election-open-header"
      className={className}
      aside={
        <span className="inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap">
          <LiveDot />
          {t('elections.status.open')}
        </span>
      }
    >
      <div className="flex flex-wrap items-stretch gap-4.5">
        <div className="flex min-w-0 flex-[1_1_22rem] flex-col gap-2.5">
          <span className="text-sm font-bold tracking-wider text-ink-soft uppercase">
            {t('elections.open.left')}
          </span>
          <div
            data-testid="election-open-countdown"
            role="timer"
            aria-label={
              left
                ? t('elections.open.countdown', {
                    days: left.days,
                    hours: left.hours,
                    minutes: left.minutes,
                    seconds: left.seconds,
                  })
                : undefined
            }
            className="grid grid-cols-4 gap-2 sm:gap-3"
          >
            {units.map((unit) => (
              <div
                key={unit.key}
                className="flex min-w-0 flex-col items-center gap-1 rounded-md border border-primary-line bg-primary-soft px-1 py-3"
              >
                <span
                  aria-hidden="true"
                  className="font-display text-3xl leading-none font-extrabold text-deep tabular-nums"
                >
                  {unit.value === undefined ? '--' : pad(unit.value)}
                </span>
                <span className="max-w-full text-xs font-medium text-ink-2 sm:text-sm">
                  {unit.label}
                </span>
              </div>
            ))}
          </div>
          {over ? (
            <p role="status" className="text-base font-medium text-ink-soft">
              {t('elections.open.over')}
            </p>
          ) : null}
        </div>

        <div className="flex min-w-0 flex-[1_1_18rem] flex-col justify-between gap-4">
          <div className="flex items-start gap-3.5 rounded-md bg-canvas px-3.5 py-3">
            <span className="mt-0.5 text-primary">
              <Icon name="calendar" size={22} />
            </span>
            <span className="min-w-0">
              <small className="block text-xs font-bold tracking-wider text-ink-soft uppercase">
                {t('elections.open.ends')}
              </small>
              <b
                data-testid="election-open-ends"
                className="text-md font-medium break-words text-ink"
              >
                {momentText(end, locale, election.timezone, t)}
              </b>
              <span className="block text-sm break-all text-ink-soft">{election.timezone}</span>
            </span>
          </div>
          <div className="flex flex-wrap gap-2.5">
            <button
              type="button"
              onClick={onExtend}
              data-testid="election-extend-button"
              className={cx(BUTTON, 'border border-primary bg-primary font-bold text-surface')}
            >
              <Icon name="clock" size={20} />
              {t('elections.open.extend')}
            </button>
            <button
              type="button"
              onClick={onClose}
              data-testid="election-close-button"
              className={cx(BUTTON, 'border border-danger-line bg-surface text-danger')}
            >
              <Icon name="lock" size={20} />
              {t('elections.open.close')}
            </button>
          </div>
        </div>
      </div>
    </Panel>
  );
}
