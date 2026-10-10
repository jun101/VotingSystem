'use client';

import {
  useEffect,
  useId,
  useRef,
  useState,
  useSyncExternalStore,
  type ChangeEvent,
  type KeyboardEvent,
} from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { durationBetween } from '@/lib/format/electionDates';
import { fromLocalInput } from '@/lib/format/zonedTime';
import { useI18n } from '@/lib/i18n/client';
import type { MessageKey } from '@/lib/i18n/messages';
import {
  PRESETS,
  addDays,
  addMonths,
  markOf,
  monthGrid,
  monthOf,
  pickDay,
  pickTime,
  presetRange,
  splitLocal,
  stepTime,
  todayIn,
  type Month,
  type PresetId,
  type Range,
  type Which,
} from './pickerLogic';
import { durationText } from './scheduleText';

const CHIPS = ['08:00', '12:00', '17:00', '19:00'] as const;

const PRESET_LABELS: Record<
  PresetId,
  { key: MessageKey; icon: 'sun' | 'flag' | 'calendar' | 'moon' }
> = {
  tomorrow: { key: 'elections.picker.presets.tomorrow', icon: 'sun' },
  'next-monday': { key: 'elections.picker.presets.nextMonday', icon: 'flag' },
  'in-a-week': { key: 'elections.picker.presets.inAWeek', icon: 'calendar' },
  'one-day': { key: 'elections.picker.presets.oneDay', icon: 'moon' },
};

const WIDE = '(min-width: 768px)';

function subscribeWide(notify: () => void): () => void {
  const query = window.matchMedia(WIDE);

  query.addEventListener('change', notify);

  return () => query.removeEventListener('change', notify);
}

/** Whether two months fit side by side (`md` and up); a phone gets one month and a bottom sheet. */
function useWide(): boolean {
  return useSyncExternalStore(
    subscribeWide,
    () => window.matchMedia(WIDE).matches,
    () => false,
  );
}

/** A civil day written by the browser's own language data, in UTC so no zone moves it. */
function written(day: string, locale: string, options: Intl.DateTimeFormatOptions): string {
  const [year, month, date] = day.split('-').map(Number) as [number, number, number];

  return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(
    new Date(Date.UTC(year, month - 1, date, 12)),
  );
}

/** One of the two big tiles: a day badge, the time in the display font and the date in words. */
function DateTile({
  which,
  value,
  label,
  empty,
  active,
  invalid,
  expanded,
  controls,
  describedBy,
  onClick,
  buttonRef,
}: {
  which: Which;
  value: string;
  label: string;
  empty: string;
  active: boolean;
  invalid: boolean;
  expanded: boolean;
  controls: string;
  describedBy?: string;
  onClick: () => void;
  buttonRef: (element: HTMLButtonElement | null) => void;
}) {
  const { locale } = useI18n();
  const parts = splitLocal(value);

  return (
    <button
      ref={buttonRef}
      type="button"
      aria-haspopup="dialog"
      aria-expanded={expanded}
      aria-controls={expanded ? controls : undefined}
      aria-describedby={describedBy}
      data-testid={`election-${which}-tile`}
      onClick={onClick}
      className={cx(
        'ui-control lift-sm flex min-w-0 items-center gap-3 rounded-lg border-2 bg-surface p-2.5 text-left shadow-1 hover:shadow-2 sm:gap-3.5 sm:p-3',
        invalid ? 'border-danger' : active ? 'border-primary' : 'border-line',
        active && 'ring-4 ring-primary-soft',
        'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary',
      )}
    >
      <span
        aria-hidden="true"
        className={cx(
          'flex h-16 w-14 shrink-0 flex-col items-center justify-center rounded-md text-surface shadow-2 sm:h-[72px] sm:w-[68px]',
          which === 'starts' ? 'bg-day-start' : 'bg-day-end',
        )}
      >
        {parts ? (
          <>
            <small className="text-xs font-bold tracking-wider uppercase">
              {written(parts.day, locale, { month: 'short' })}
            </small>
            <b className="font-display text-[26px] leading-none font-extrabold sm:text-3xl">
              {Number(parts.day.slice(8, 10))}
            </b>
            <small className="text-xs font-bold tracking-wider uppercase">
              {written(parts.day, locale, { weekday: 'short' })}
            </small>
          </>
        ) : (
          <Icon name="calendar" size={28} />
        )}
      </span>
      <span className="flex min-w-0 flex-col">
        <small className="text-xs font-bold tracking-wider text-ink-soft uppercase">{label}</small>
        <b className="flex items-center gap-2 font-display text-xl font-extrabold text-ink sm:text-2xl">
          <span className={which === 'starts' ? 'text-primary' : 'text-hero-from'}>
            <Icon name="clock" size={20} />
          </span>
          {parts ? parts.time : empty}
        </b>
        {parts ? (
          <span className="truncate text-base text-ink-soft">
            {written(parts.day, locale, {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
              year: 'numeric',
            })}
          </span>
        ) : null}
      </span>
    </button>
  );
}

/**
 * The start and the end of the vote: two big tiles that open a popover (a bottom sheet on a
 * phone) with quick choices, a calendar of two months (one on a phone), time chips and steppers,
 * the length of the vote and the time zone. The two native `datetime-local` fields stay in the
 * page, visually hidden but reachable, as the keyboard and screen reader path and the value the
 * form reads; the tiles and the popover write to them, and a change in one shows in the other.
 * All the calendar maths are those of the election's time zone, never the browser's.
 */
export function DateRangePicker({
  starts,
  ends,
  zone,
  onChange,
  errors,
}: {
  /** The text of the native `datetime-local` fields. */
  starts: string;
  ends: string;
  zone: string;
  /** What changed: one field when typed in a native field, both when picked. */
  onChange: (next: Partial<Range>) => void;
  errors: { starts?: string; ends?: string };
}) {
  const { t, locale } = useI18n();
  const wide = useWide();
  const id = useId();
  const popoverId = `${id}-popover`;
  const range: Range = { starts, ends };
  const today = todayIn(zone);
  const [editing, setEditing] = useState<Which | null>(null);
  const [view, setView] = useState<Month>(() => monthOf(today) ?? { year: 1970, month: 1 });
  const wrapper = useRef<HTMLDivElement>(null);
  const popover = useRef<HTMLDivElement>(null);
  const tiles = useRef<Record<Which, HTMLButtonElement | null>>({ starts: null, ends: null });
  // What the fields held when the popover opened: "Annuler" puts it back.
  const opened = useRef<Range>(range);
  const focusAfterView = useRef<string | null>(null);
  const nativeFields = useRef<Record<Which, HTMLInputElement | null>>({ starts: null, ends: null });

  // A date typed in a native field before the page was ready is not lost when it becomes ready.
  useEffect(() => {
    const typed: Partial<Range> = {};

    for (const which of ['starts', 'ends'] as const) {
      const value = nativeFields.current[which]?.value ?? '';

      if (value !== '' && range[which] === '') typed[which] = value;
    }

    if (typed.starts !== undefined || typed.ends !== undefined) onChange(typed);
    // Once, when the page starts.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function showMonthOf(which: Which, from: Range) {
    const day = splitLocal(from[which])?.day ?? splitLocal(from.starts)?.day ?? today;

    setView(monthOf(day) ?? { year: 1970, month: 1 });
  }

  function openFor(which: Which) {
    if (editing === null) opened.current = range;

    setEditing(which);
    showMonthOf(which, range);
  }

  function close(returnFocus: boolean) {
    const was = editing;

    setEditing(null);

    if (returnFocus && was) tiles.current[was]?.focus();
  }

  function toggle(which: Which) {
    if (editing === which) close(false);
    else openFor(which);
  }

  // The focus enters the popover when it opens, on the day being edited; on a phone the tiles
  // scroll to the top so they stay in view above the sheet.
  const isOpen = editing !== null;

  useEffect(() => {
    if (!isOpen) return;

    if (!wide) wrapper.current?.scrollIntoView({ block: 'start' });

    popover.current
      ?.querySelector<HTMLElement>('[data-day-tab="true"]')
      ?.focus({ preventScroll: true });
    // Only when it opens: a pick must not pull the focus back.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    function onKey(event: globalThis.KeyboardEvent) {
      if (event.key !== 'Escape') return;

      event.preventDefault();
      close(true);
    }

    function onPointer(event: PointerEvent) {
      const target = event.target as Node;

      if (wrapper.current?.contains(target)) return;

      // A phone's sheet has no backdrop, so a touch on the page (a scroll) must not close it.
      if (window.matchMedia(WIDE).matches) close(false);
    }

    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointer);

    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPointer);
    };
    // `close` reads the editing field of this render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, editing]);

  // A move by keyboard to a day of another month: show that month, then focus the day.
  useEffect(() => {
    const day = focusAfterView.current;

    if (!day) return;

    focusAfterView.current = null;
    popover.current?.querySelector<HTMLElement>(`[data-day="${day}"]`)?.focus();
  }, [view]);

  const startOk = splitLocal(starts);
  const startInstant = fromLocalInput(starts, zone);
  const endInstant = fromLocalInput(ends, zone);
  const length =
    startInstant && endInstant ? durationText(startInstant.utc, endInstant.utc, locale, t) : null;
  const wrongOrder =
    startInstant && endInstant ? durationBetween(startInstant.utc, endInstant.utc) === null : false;
  const edited = editing ?? 'starts';
  const current = splitLocal(range[edited]);
  const activeDay = current?.day ?? splitLocal(range.starts)?.day ?? today;

  function nativeChange(which: Which) {
    return (event: ChangeEvent<HTMLInputElement>) => onChange({ [which]: event.target.value });
  }

  function onDayKey(event: KeyboardEvent<HTMLButtonElement>, day: string) {
    const step: Record<string, number> = {
      ArrowLeft: -1,
      ArrowRight: 1,
      ArrowUp: -7,
      ArrowDown: 7,
    };
    const move = step[event.key];

    if (move === undefined) return;

    event.preventDefault();

    const target = addDays(day, move);
    const found = popover.current?.querySelector<HTMLElement>(`[data-day="${target}"]`);

    if (found) {
      found.focus();

      return;
    }

    const month = monthOf(target);

    if (month) {
      focusAfterView.current = target;
      setView(month);
    }
  }

  const monthCount = wide ? 2 : 1;

  function renderMonth(offset: number) {
    const month = addMonths(view, offset);
    const titleId = `${id}-month-${offset}`;
    const title = written(`${month.year}-${String(month.month).padStart(2, '0')}-01`, locale, {
      month: 'long',
      year: 'numeric',
    });
    const first = offset === 0;
    const last = offset === monthCount - 1;
    // The day that is reachable with Tab: the edited one when it is on screen, else the 1st.
    const shown = Array.from({ length: monthCount }, (_, index) => addMonths(view, index));
    const activeVisible = shown.some(
      (m) => monthOf(activeDay)?.year === m.year && monthOf(activeDay)?.month === m.month,
    );

    return (
      <div
        key={offset}
        data-testid={`picker-month-${offset}`}
        className="min-w-0 flex-1 basis-[230px]"
      >
        <div className="mb-2 flex items-center justify-between">
          {first ? (
            <button
              type="button"
              onClick={() => setView(addMonths(view, -1))}
              aria-label={t('elections.picker.previousMonth')}
              data-testid="picker-previous-month"
              className="ui-control flex size-[34px] items-center justify-center rounded-full bg-canvas text-ink hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
            >
              <Icon name="left" size={18} />
            </button>
          ) : (
            <span className="size-[34px]" />
          )}
          <h3 id={titleId} className="font-display text-lg font-extrabold capitalize">
            {title}
          </h3>
          {last ? (
            <button
              type="button"
              onClick={() => setView(addMonths(view, 1))}
              aria-label={t('elections.picker.nextMonth')}
              data-testid="picker-next-month"
              className="ui-control flex size-[34px] items-center justify-center rounded-full bg-canvas text-ink hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
            >
              <Icon name="right" size={18} />
            </button>
          ) : (
            <span className="size-[34px]" />
          )}
        </div>
        <div role="group" aria-labelledby={titleId} className="grid grid-cols-7 text-center">
          {Array.from({ length: 7 }, (_, index) => (
            <b key={index} aria-hidden="true" className="pb-1.5 text-xs font-bold text-ink-soft">
              {written(addDays('2024-01-01', index), locale, { weekday: 'narrow' })}
            </b>
          ))}
          {monthGrid(month).map((day, index) => {
            if (day === null) return <span key={`blank-${index}`} aria-hidden="true" />;

            const mark = markOf(day, range);
            const single = mark === 'start' && splitLocal(ends)?.day === day;
            const tab = activeVisible ? day === activeDay : day.endsWith('-01') && first;
            const label = written(day, locale, { day: 'numeric', month: 'long', year: 'numeric' });
            const note =
              mark === 'start'
                ? t('elections.picker.dayStart')
                : mark === 'end'
                  ? t('elections.picker.dayEnd')
                  : '';

            return (
              <button
                key={day}
                type="button"
                data-testid={`picker-day-${day}`}
                data-day={day}
                data-day-tab={tab ? 'true' : undefined}
                data-range={mark}
                data-single={single ? 'true' : undefined}
                data-today={day === today ? 'true' : undefined}
                tabIndex={tab ? 0 : -1}
                aria-label={note ? `${label}, ${note}` : label}
                aria-current={day === today ? 'date' : undefined}
                onClick={() => onChange(pickDay(edited, day, range, today))}
                onKeyDown={(event) => onDayKey(event, day)}
                className="picker-day"
              >
                <span aria-hidden="true" className="picker-day-n">
                  {Number(day.slice(8, 10))}
                </span>
              </button>
            );
          })}
        </div>
      </div>
    );
  }

  const time = current?.time ?? (edited === 'starts' ? '08:00' : '17:00');
  const [hour, minute] = time.split(':');
  const startError = errors.starts ? `${id}-starts-error` : undefined;
  const endError = errors.ends ? `${id}-ends-error` : undefined;

  const stepper = (unit: 'hour' | 'minute', value: string, label: string) => (
    <div className="flex items-center justify-between gap-2">
      <span className="text-sm font-bold text-ink-soft">{label}</span>
      <div className="flex items-center gap-1.5">
        <button
          type="button"
          data-testid={`picker-${unit}-minus`}
          aria-label={t(
            unit === 'hour' ? 'elections.picker.hourMinus' : 'elections.picker.minuteMinus',
          )}
          onClick={() => onChange(stepTime(edited, unit, -1, range, today))}
          className="ui-control flex size-[34px] items-center justify-center rounded-full bg-surface text-primary shadow-1 hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
        >
          <Icon name="minus" size={18} />
        </button>
        <span
          data-testid={`picker-${unit}-value`}
          className="grid h-11 min-w-[64px] place-items-center rounded-md border-2 border-primary bg-surface font-display text-xl font-extrabold"
        >
          {value}
        </span>
        <button
          type="button"
          data-testid={`picker-${unit}-plus`}
          aria-label={t(
            unit === 'hour' ? 'elections.picker.hourPlus' : 'elections.picker.minutePlus',
          )}
          onClick={() => onChange(stepTime(edited, unit, 1, range, today))}
          className="ui-control flex size-[34px] items-center justify-center rounded-full bg-surface text-primary shadow-1 hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
        >
          <Icon name="plus" size={18} />
        </button>
      </div>
    </div>
  );

  const chipBase =
    'ui-control inline-flex h-9 items-center rounded-full border px-3 text-base font-medium focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

  return (
    <div ref={wrapper} className="relative scroll-mt-24">
      <div
        role="group"
        aria-label={t('elections.picker.planning')}
        className="grid gap-3 sm:grid-cols-2"
      >
        <DateTile
          which="starts"
          value={starts}
          label={t('elections.picker.start')}
          empty={t('elections.picker.empty')}
          active={editing === 'starts'}
          invalid={Boolean(errors.starts)}
          expanded={editing === 'starts'}
          controls={popoverId}
          describedBy={startError}
          onClick={() => toggle('starts')}
          buttonRef={(element) => {
            tiles.current.starts = element;
          }}
        />
        <DateTile
          which="ends"
          value={ends}
          label={t('elections.picker.end')}
          empty={t('elections.picker.empty')}
          active={editing === 'ends'}
          invalid={Boolean(errors.ends) || wrongOrder}
          expanded={editing === 'ends'}
          controls={popoverId}
          describedBy={endError}
          onClick={() => toggle('ends')}
          buttonRef={(element) => {
            tiles.current.ends = element;
          }}
        />
      </div>

      {errors.starts ? (
        <p
          id={startError}
          data-testid="election-starts-error"
          className="mt-2 text-sm font-medium text-danger"
        >
          {errors.starts}
        </p>
      ) : null}
      {errors.ends ? (
        <p
          id={endError}
          data-testid="election-ends-error"
          className="mt-2 text-sm font-medium text-danger"
        >
          {errors.ends}
        </p>
      ) : null}

      {/* The native fields: out of sight, never out of reach. They hold the form's value. */}
      <div className="sr-only">
        <label htmlFor={`${id}-starts`}>{t('elections.form.starts')}</label>
        <input
          id={`${id}-starts`}
          name="starts_at"
          type="datetime-local"
          required
          ref={(element) => {
            nativeFields.current.starts = element;
          }}
          value={starts}
          onChange={nativeChange('starts')}
          aria-invalid={errors.starts ? 'true' : undefined}
          aria-describedby={startError}
          data-testid="election-starts"
        />
        <label htmlFor={`${id}-ends`}>{t('elections.form.ends')}</label>
        <input
          id={`${id}-ends`}
          name="ends_at"
          type="datetime-local"
          required
          ref={(element) => {
            nativeFields.current.ends = element;
          }}
          value={ends}
          onChange={nativeChange('ends')}
          aria-invalid={errors.ends ? 'true' : undefined}
          aria-describedby={endError}
          data-testid="election-ends"
        />
      </div>

      {isOpen ? (
        <div
          ref={popover}
          id={popoverId}
          role="dialog"
          aria-label={t('elections.picker.title')}
          data-testid="date-picker"
          className="picker-rise fixed inset-x-0 bottom-0 z-40 flex max-h-[55dvh] flex-col rounded-t-xl bg-surface shadow-3 md:absolute md:inset-x-auto md:top-full md:bottom-auto md:left-0 md:z-30 md:mt-2 md:max-h-none md:w-[min(44rem,100%)] md:rounded-xl"
        >
          <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 md:overflow-visible md:p-5">
            <div
              role="group"
              aria-label={t('elections.picker.presetsLabel')}
              className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:overflow-visible md:px-0 md:pb-0"
            >
              {PRESETS.map((preset) => {
                const next = presetRange(preset, range, today);
                const selected = next.starts === starts && next.ends === ends;

                return (
                  <button
                    key={preset}
                    type="button"
                    data-testid={`picker-preset-${preset}`}
                    aria-pressed={selected}
                    onClick={() => {
                      onChange(next);
                      showMonthOf('starts', next);
                    }}
                    className={cx(
                      chipBase,
                      'shrink-0 gap-2',
                      selected
                        ? 'border-primary-line bg-primary-soft text-status-scheduled'
                        : 'border-line bg-surface text-ink hover:bg-primary-soft',
                    )}
                  >
                    <span className="text-primary">
                      <Icon name={PRESET_LABELS[preset].icon} size={17} />
                    </span>
                    {t(PRESET_LABELS[preset].key)}
                  </button>
                );
              })}
            </div>

            <div className="flex flex-wrap gap-x-[22px] gap-y-4">
              {Array.from({ length: monthCount }, (_, index) => renderMonth(index))}
            </div>

            <div className="flex flex-col gap-3 rounded-lg bg-canvas p-3.5">
              <div className="flex items-center justify-between gap-2">
                <h4 className="flex items-center gap-2 text-sm font-bold tracking-wide text-ink-soft uppercase">
                  <span className={edited === 'starts' ? 'text-primary' : 'text-hero-from'}>
                    <Icon name="clock" size={18} />
                  </span>
                  {current
                    ? t(
                        edited === 'starts'
                          ? 'elections.picker.timeStart'
                          : 'elections.picker.timeEnd',
                        {
                          date: written(current.day, locale, { day: 'numeric', month: 'short' }),
                        },
                      )
                    : t(edited === 'starts' ? 'elections.picker.start' : 'elections.picker.end') +
                      ' · ' +
                      t('elections.picker.timeNone')}
                </h4>
                <div className="flex gap-1">
                  {(['starts', 'ends'] as const).map((which) => (
                    <button
                      key={which}
                      type="button"
                      aria-pressed={edited === which}
                      data-testid={`picker-edit-${which}`}
                      onClick={() => {
                        setEditing(which);
                        showMonthOf(which, range);
                      }}
                      className={cx(
                        chipBase,
                        'h-8',
                        edited === which
                          ? 'border-primary bg-primary text-surface'
                          : 'border-line bg-surface text-ink hover:bg-primary-soft',
                      )}
                    >
                      {t(which === 'starts' ? 'elections.picker.start' : 'elections.picker.end')}
                    </button>
                  ))}
                </div>
              </div>
              <div
                role="group"
                aria-label={t('elections.picker.chipsLabel')}
                className="flex flex-wrap gap-1.5"
              >
                {CHIPS.map((chip) => (
                  <button
                    key={chip}
                    type="button"
                    data-testid={`picker-time-chip-${chip}`}
                    aria-pressed={time === chip && current !== null}
                    onClick={() => onChange(pickTime(edited, chip, range, today))}
                    className={cx(
                      chipBase,
                      time === chip && current !== null
                        ? 'border-primary bg-primary text-surface'
                        : 'border-line bg-surface text-ink hover:bg-primary-soft',
                    )}
                  >
                    {chip}
                  </button>
                ))}
              </div>
              <div className="grid gap-2 sm:grid-cols-2 sm:gap-6">
                {stepper('hour', hour ?? '00', t('elections.picker.hour'))}
                {stepper('minute', minute ?? '00', t('elections.picker.minute'))}
              </div>
            </div>

            <div
              data-testid="picker-summary"
              aria-live="polite"
              className="flex items-center gap-3 rounded-lg bg-canvas p-3.5 text-base"
            >
              <span
                aria-hidden="true"
                className="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-teal-soft text-teal-ink"
              >
                <Icon name="check" size={20} />
              </span>
              <span className="min-w-0 font-medium">
                {length
                  ? t('elections.schedule.duration', { duration: length, zone })
                  : startOk && wrongOrder
                    ? t('elections.schedule.invalid')
                    : t('elections.schedule.durationNone')}
              </span>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-line-soft p-3 md:flex-nowrap md:px-5">
            <span className="flex min-w-0 items-center gap-2 text-sm text-ink-soft md:flex-1">
              <span className="text-primary">
                <Icon name="globe" size={18} />
              </span>
              <span data-testid="picker-zone" className="break-all md:truncate">
                {t('elections.picker.zone', { zone })}
              </span>
            </span>
            <span className="flex-1 md:hidden" />
            <Button
              variant="quiet"
              data-testid="picker-cancel"
              onClick={() => {
                onChange(opened.current);
                close(true);
              }}
            >
              {t('elections.picker.cancel')}
            </Button>
            <Button data-testid="picker-apply" onClick={() => close(true)}>
              <span className="inline-flex items-center gap-2">
                <Icon name="check" size={20} />
                {t('elections.picker.apply')}
              </span>
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
