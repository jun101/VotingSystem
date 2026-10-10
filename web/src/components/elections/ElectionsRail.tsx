'use client';

import Link from 'next/link';
import { useState, type ReactNode } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing, linkPrimary } from '@/components/admin/classes';
import { Reveal } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { Election } from '@/lib/api/elections';
import { useI18n } from '@/lib/i18n/client';
import { DEFAULT_TIME_ZONE } from '@/lib/format/timeZones';
import { calendarFor, todoFor } from './railModel';

const capital = (text: string) => text.charAt(0).toLocaleUpperCase() + text.slice(1);

/** The panel frame: a white card with a round icon and a title. */
function Panel({
  icon,
  title,
  titleId,
  children,
  ...rest
}: {
  icon: 'calendar' | 'flag';
  title: string;
  titleId: string;
  children: ReactNode;
  'data-testid'?: string;
}) {
  return (
    <section
      aria-labelledby={titleId}
      className="rounded-lg bg-surface px-5 py-[18px] shadow-2"
      {...rest}
    >
      <h2
        id={titleId}
        className="mb-3 flex items-center gap-2.5 font-display text-lg font-extrabold"
      >
        <span
          aria-hidden="true"
          className="flex size-[34px] items-center justify-center rounded-full bg-primary-soft text-primary"
        >
          <Icon name={icon} size={18} />
        </span>
        {title}
      </h2>
      {children}
    </section>
  );
}

/**
 * The right rail of the elections list, from 1600 px: the month calendar of the nearest upcoming
 * election (its days marked, today ringed) and what is left to do. Below 1600 px it is hidden.
 */
export function ElectionsRail({ elections, now }: { elections: Election[]; now?: Date }) {
  const { t, locale } = useI18n();
  const [mounted] = useState(() => now ?? new Date());
  const moment = now ?? mounted;
  const calendar = calendarFor(elections, moment, DEFAULT_TIME_ZONE);
  const todo = todoFor(elections, moment);
  // The month and the weekday initials come from the browser's own language data.
  const monthTitle = capital(
    new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
      new Date(Date.UTC(calendar.month.year, calendar.month.month - 1, 1, 12)),
    ),
  );
  const initials = Array.from({ length: 7 }, (_, index) =>
    new Intl.DateTimeFormat(locale, { weekday: 'narrow', timeZone: 'UTC' }).format(
      // 2024-01-01 is a Monday.
      new Date(Date.UTC(2024, 0, 1 + index, 12)),
    ),
  );

  return (
    <aside
      data-testid="elections-rail"
      aria-label={t('elections.rail.label')}
      className="hidden w-[340px] shrink-0 self-start 2xl:sticky 2xl:top-20 2xl:block"
    >
      <Reveal stagger className="flex flex-col gap-4">
        <Panel
          icon="calendar"
          title={monthTitle}
          titleId="rail-calendar-title"
          data-testid="rail-calendar"
        >
          <div
            role="group"
            aria-label={t('elections.rail.calendar.title')}
            className="grid grid-cols-7 gap-1 text-center text-sm"
          >
            {initials.map((letter, index) => (
              <b key={index} aria-hidden="true" className="pb-1 text-xs font-bold text-ink-soft">
                {letter}
              </b>
            ))}
            {calendar.weeks.flat().map((cell) => (
              <span
                key={cell.day}
                data-testid={`rail-day-${cell.day}`}
                data-event={cell.event ? 'true' : undefined}
                aria-current={cell.today ? 'date' : undefined}
                className={cx(
                  'grid h-[34px] place-items-center rounded-full',
                  cell.today
                    ? 'bg-primary font-bold text-surface'
                    : cell.event
                      ? 'bg-primary-soft font-bold text-status-scheduled'
                      : cell.outside
                        ? 'text-ink-soft'
                        : 'text-ink',
                  cell.today && cell.event && 'ring-2 ring-primary-line ring-offset-2',
                )}
              >
                {cell.number}
                {cell.event ? (
                  <span className="sr-only">{t('elections.rail.calendar.eventDay')}</span>
                ) : null}
              </span>
            ))}
          </div>
        </Panel>

        <Panel icon="flag" title={t('elections.rail.todo.title')} titleId="rail-todo-title">
          {todo.length === 0 ? (
            <div data-testid="rail-todo-empty" className="flex flex-col items-start gap-3">
              <p className="text-base text-ink-soft">
                {t(
                  elections.length === 0
                    ? 'elections.rail.todo.emptyFirst'
                    : 'elections.rail.todo.emptyDone',
                )}
              </p>
              {elections.length === 0 ? (
                <Link href="/admin/elections/new" className={cx(linkPrimary, 'gap-2')}>
                  <Icon name="plus" size={18} />
                  {t('elections.rail.todo.emptyCreate')}
                </Link>
              ) : null}
            </div>
          ) : (
            <ul className="flex flex-col gap-1">
              {todo.map((item, index) => {
                const draft = item.kind === 'draft';
                const when =
                  item.inDays <= 0
                    ? t('elections.rail.todo.today')
                    : item.inDays === 1
                      ? t('elections.rail.todo.tomorrow')
                      : t('elections.rail.todo.days', { count: item.inDays });

                return (
                  <li key={item.election.id}>
                    <Link
                      href={`/admin/elections/${item.election.id}${draft ? '/edit' : ''}`}
                      data-testid={`rail-todo-${index + 1}`}
                      className={`ui-control flex items-center gap-3 rounded-md px-3 py-2.5 text-base font-medium text-ink hover:bg-canvas ${focusRing}`}
                    >
                      <span
                        aria-hidden="true"
                        className={cx(
                          'flex size-8 shrink-0 items-center justify-center rounded-full',
                          draft ? 'bg-warm-soft text-warm' : 'bg-primary-soft text-primary',
                        )}
                      >
                        <Icon name={draft ? 'draft' : 'clock'} size={17} />
                      </span>
                      <span className="flex min-w-0 flex-col">
                        <span>
                          {t(draft ? 'elections.rail.todo.draft' : 'elections.rail.todo.soon')}
                        </span>
                        <span className="truncate text-sm font-normal text-ink-soft">
                          {draft
                            ? item.election.title
                            : t('elections.rail.todo.withWhen', {
                                title: item.election.title,
                                when,
                              })}
                        </span>
                      </span>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Panel>
      </Reveal>
    </aside>
  );
}
