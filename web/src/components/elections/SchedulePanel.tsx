'use client';

import { fromLocalInput } from '@/lib/format/zonedTime';
import { useI18n } from '@/lib/i18n/client';
import { durationText, scheduleLine } from './scheduleText';

/**
 * The schedule in words, in the election's time zone, recomputed as the person types: the two
 * dates, the length of the vote and the zone. A time that a clock change skips is moved to the
 * nearest one that exists, and the line shows that time and says so.
 */
export function SchedulePanel({
  starts,
  ends,
  zone,
}: {
  /** The text of a `datetime-local` field. */
  starts: string;
  ends: string;
  zone: string;
}) {
  const { t, locale } = useI18n();
  const start = fromLocalInput(starts, zone);
  const end = fromLocalInput(ends, zone);

  let line: string = t('elections.schedule.empty');
  let duration: string = t('elections.schedule.durationNone');
  let invalid = false;

  if (start && end) {
    const length = durationText(start.utc, end.utc, locale, t);

    if (length) {
      line = scheduleLine(start.utc, end.utc, locale, zone, t);
      duration = t('elections.schedule.duration', { duration: length, zone });
    } else {
      line = t('elections.schedule.invalid');
      invalid = true;
    }
  }

  return (
    <section
      data-testid="election-schedule"
      aria-labelledby="election-schedule-title"
      aria-live="polite"
      className="bg-hero flex flex-col gap-2 rounded-lg p-4 text-surface"
    >
      <h2 id="election-schedule-title" className="text-lg font-bold">
        {t('elections.schedule.title')}
      </h2>
      <p className={invalid ? 'font-semibold text-accent-light' : 'text-base'}>{line}</p>
      <p
        data-testid="election-duration"
        className="font-display text-lg font-bold text-accent-light"
      >
        {duration}
      </p>
      {start?.adjusted || end?.adjusted ? (
        <p className="text-sm text-hero-ink-soft">{t('elections.schedule.adjusted')}</p>
      ) : null}
    </section>
  );
}
