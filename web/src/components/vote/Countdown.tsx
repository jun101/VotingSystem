'use client';

import { useEffect, useState } from 'react';
import { useI18n } from '@/lib/i18n/client';
import { isOver, remaining } from './voteTime';

/**
 * Days, hours, minutes and seconds to the start. The figures only show how long to wait: whether
 * the vote is open is the server's answer, asked again when the time is up and every few seconds
 * after (`onDue`).
 */
export function Countdown({ target, onDue }: { target: string; onDue: () => void }) {
  const { t } = useI18n();
  // Null until the page runs: the server and the first render of the browser agree.
  const [now, setNow] = useState<number | null>(null);

  useEffect(() => {
    // The clock starts in an effect (browser only), so the first render matches the server's.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setNow(Date.now());
    const timer = setInterval(() => setNow(Date.now()), 1000);

    return () => clearInterval(timer);
  }, []);

  const left = now === null ? null : remaining(target, now);
  const over = left !== null && isOver(left);

  useEffect(() => {
    if (!over) return;

    onDue();
    const poll = setInterval(onDue, 4000);

    return () => clearInterval(poll);
  }, [over, onDue]);

  const units = [
    { key: 'days', label: t('vote.before.days'), value: left?.days },
    { key: 'hours', label: t('vote.before.hours'), value: left?.hours },
    { key: 'minutes', label: t('vote.before.minutes'), value: left?.minutes },
    { key: 'seconds', label: t('vote.before.seconds'), value: left?.seconds },
  ] as const;

  return (
    <div className="flex flex-col gap-3">
      <div
        data-testid="vote-countdown"
        role="timer"
        aria-label={
          left
            ? t('vote.before.countdown', {
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
            className="flex min-w-0 flex-col items-center gap-1 rounded-md border border-primary-line bg-primary-soft px-1 py-3 sm:py-4"
          >
            <span
              aria-hidden="true"
              className="font-display text-3xl leading-none font-extrabold text-deep tabular-nums sm:text-4xl"
            >
              {unit.value === undefined ? '--' : String(unit.value).padStart(2, '0')}
            </span>
            <span className="max-w-full truncate text-xs font-medium text-ink-2 sm:text-sm">
              {unit.label}
            </span>
          </div>
        ))}
      </div>
      {over ? (
        <p role="status" className="text-center text-base font-medium text-teal-ink">
          {t('vote.before.soon')}
        </p>
      ) : null}
    </div>
  );
}
