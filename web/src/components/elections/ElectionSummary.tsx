'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { LiveDot } from '@/components/motion';
import { Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { duplicateElection } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { clock, longDay } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { DeleteElectionDialog } from './DeleteElectionDialog';
import { NextSteps } from './NextSteps';
import { Panel } from './Panel';
import { durationText, momentText } from './scheduleText';
import { dayBadge, sameYear, shortDay } from './summaryText';
import { COVER_ICONS, STATUS_ICONS } from './statusIcon';

/** The text colour of the badge on the header band, by status (the chip colours of the design). */
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

const ACTION =
  'ui-control lift-sm inline-flex h-12 items-center gap-2 rounded-full pr-5.5 pl-4 text-md font-medium disabled:opacity-60 ' +
  focusRing;

/** A row of the settings: a round icon, a small label and the value. */
function Setting({
  name,
  icon,
  tone,
  label,
  value,
}: {
  name: 'language' | 'order' | 'results';
  icon: IconName;
  tone: string;
  label: string;
  value: string;
}) {
  return (
    <div
      data-testid={`election-setting-${name}`}
      className="flex items-center gap-3.5 rounded-md px-3 py-2.5 hover:bg-canvas"
    >
      <span
        aria-hidden="true"
        className={cx('flex size-10.5 shrink-0 items-center justify-center rounded-full', tone)}
      >
        <Icon name={icon} size={22} />
      </span>
      <span className="min-w-0">
        <small className="block text-xs font-bold tracking-wider text-ink-soft uppercase">
          {label}
        </small>
        <b className="text-[16px] font-medium break-words text-ink">{value}</b>
      </span>
    </div>
  );
}

/** One of the two days of the calendar: the badge, the label, the time and the date in words. */
function Day({ which, instant, zone }: { which: 'start' | 'end'; instant: Date; zone: string }) {
  const { t, locale } = useI18n();
  const badge = dayBadge(instant, locale, zone);

  return (
    <div className="flex items-center gap-3.5">
      <div
        data-testid={`election-day-${which}`}
        className={cx(
          'flex h-23 w-21 shrink-0 flex-col items-center justify-center rounded-lg text-surface shadow-2',
          which === 'start' ? 'bg-day-badge-start' : 'bg-day-badge-end',
        )}
      >
        <small className="text-xs font-bold tracking-widest uppercase">{badge.month}</small>
        <b className="font-display text-[38px] leading-none font-extrabold">{badge.day}</b>
        <small className="text-xs font-bold tracking-widest uppercase">{badge.weekday}</small>
      </div>
      <div className="min-w-0">
        <small className="block text-xs font-bold tracking-wider text-ink-soft uppercase">
          {t(which === 'start' ? 'elections.summary.start' : 'elections.summary.end')}
        </small>
        <b className="flex items-center gap-2 font-display text-[26px] font-extrabold text-ink">
          <span className={which === 'start' ? 'text-primary' : 'text-hero-from'}>
            <Icon name="clock" size={22} />
          </span>
          {clock(instant, locale, zone)}
        </b>
        <span className="text-base break-words text-ink-soft">
          {longDay(instant, locale, zone)}
        </span>
      </div>
    </div>
  );
}

/**
 * The summary page of one election: a gradient band with the status, the title, the chips and the
 * actions (edit, duplicate and delete for a draft; only duplicate for any other status), then one
 * white zone of cards: the calendar, the settings, the cover, the figures and the next steps.
 * Only data that exists is shown.
 */
export function ElectionSummary({ election }: { election: Election }) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const draft = election.status === 'draft';
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const start = new Date(election.starts_at);
  const end = new Date(election.ends_at);
  const zone = election.timezone;
  const length = durationText(start, end, locale, t);
  const dates = t('elections.schedule.line', {
    start: momentText(start, locale, zone, t),
    end: momentText(end, locale, zone, t),
  });
  const yearsDiffer = !sameYear(start, end, zone);

  async function duplicate() {
    setProblem(null);
    setBusy(true);

    try {
      const copy = await duplicateElection(election.id);

      router.push(`/admin/elections/${copy.id}`);
    } catch (caught) {
      setProblem(
        errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny),
      );
      setBusy(false);
    }
  }

  return (
    <div data-testid="election-page" className="flex flex-col gap-4.5">
      <Link
        href="/admin/elections"
        className={cx(
          'ui-control inline-flex w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink',
          focusRing,
        )}
      >
        <Icon name="left" size={18} />
        {t('elections.summary.back')}
      </Link>

      <section
        data-testid="election-hero"
        data-status={election.status}
        aria-labelledby="election-summary-title"
        className="election-hero cover-sweep relative flex min-h-44 flex-wrap items-end gap-x-6 gap-y-5 rounded-xl px-5 py-6.5 text-surface shadow-2 md:px-7.5"
      >
        <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
          <Icon name={COVER_ICONS[election.status]} size={230} />
        </span>
        <div className="relative z-10 min-w-0 flex-[1_1_28rem]">
          <span
            data-testid="election-hero-badge"
            className={cx(
              'inline-flex h-7.5 items-center gap-2 rounded-full bg-surface/95 px-3.5 text-sm font-bold',
              BADGE_TEXT[election.status],
            )}
          >
            {election.status === 'open' ? (
              <LiveDot />
            ) : (
              <Icon name={STATUS_ICONS[election.status]} size={17} />
            )}
            <span data-status={election.status} data-testid="election-summary-status">
              {t(`elections.status.${election.status}`)}
            </span>
          </span>
          <h2
            id="election-summary-title"
            data-testid="election-summary-title"
            className="mt-2.5 mb-1 font-display text-3xl leading-tight font-extrabold break-words text-surface md:text-[40px]"
          >
            {election.title}
          </h2>
          {election.description ? (
            <p className="line-clamp-2 max-w-3xl text-md break-words whitespace-pre-line text-surface">
              {election.description}
            </p>
          ) : null}
          <div className="mt-3.5 flex flex-wrap gap-2">
            <span data-testid="election-chip-dates" className={CHIP}>
              <Icon name="calendar" size={18} />
              {t('elections.schedule.line', {
                start: shortDay(start, locale, zone, yearsDiffer),
                end: shortDay(end, locale, zone, true),
              })}
            </span>
            {length ? (
              <span data-testid="election-chip-duration" className={CHIP}>
                <Icon name="clock" size={18} />
                {length}
              </span>
            ) : null}
            <span data-testid="election-chip-language" className={CHIP}>
              <Icon name="lang" size={18} />
              {t(`elections.form.languages.${election.language}`)}
            </span>
          </div>
        </div>

        <div
          role="group"
          aria-label={t('elections.summary.actions')}
          className="relative z-10 flex flex-wrap gap-2.5 md:ml-auto"
        >
          {draft ? (
            <Link
              href={`/admin/elections/${election.id}/edit`}
              data-testid="election-edit"
              className={cx(
                ACTION,
                'border border-accent bg-accent font-bold text-deep shadow-button',
              )}
            >
              <Icon name="edit" size={20} />
              {t('elections.summary.edit')}
            </Link>
          ) : null}
          <button
            type="button"
            disabled={busy}
            aria-busy={busy || undefined}
            onClick={duplicate}
            data-testid="election-duplicate"
            className={cx(
              ACTION,
              'border border-glass-line bg-glass text-surface hover:bg-glass-line',
            )}
          >
            <Icon name="copy" size={20} />
            {t('elections.summary.duplicate')}
          </button>
          {draft ? (
            <button
              type="button"
              disabled={busy}
              onClick={() => setAsking(true)}
              data-testid="election-delete"
              className={cx(ACTION, 'border border-surface bg-surface text-danger')}
            >
              <Icon name="trash" size={20} />
              {t('elections.summary.delete')}
            </button>
          ) : null}
        </div>
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="election-summary-error">
          {problem}
        </Notice>
      ) : null}

      <section
        data-testid="election-zone"
        aria-label={t('elections.summary.zone')}
        className="flex flex-col gap-4.5 rounded-2xl border border-line bg-surface p-5 shadow-1"
      >
        {draft ? null : <Notice tone="info">{t('elections.summary.locked')}</Notice>}

        <div className="grid grid-cols-1 gap-4.5 min-[900px]:grid-cols-12">
          <Panel
            tone="calendar"
            icon="calendar"
            title={t('elections.form.sections.calendar')}
            id="election-calendar-title"
            testId="election-card-calendar"
            headerTestId="election-card-header-calendar"
            className="min-[900px]:col-span-6 min-[1500px]:col-span-5"
          >
            <p data-testid="election-summary-dates" className="sr-only left-0">
              {dates}
            </p>
            <Day which="start" instant={start} zone={zone} />
            <div
              data-testid="election-duration-line"
              className="flex items-center gap-2.5 text-base font-medium text-ink-soft"
            >
              <i aria-hidden="true" className="span-line" />
              {length ?? t('elections.schedule.durationNone')}
              <i aria-hidden="true" className="span-line span-line-end" />
            </div>
            <Day which="end" instant={end} zone={zone} />
            <p className="flex items-center gap-2.5 rounded-md bg-canvas px-3.5 py-3 text-base text-ink">
              <span className="text-primary">
                <Icon name="globe" size={20} />
              </span>
              <span className="text-ink-soft">{t('elections.summary.timezone')}</span>
              <span data-testid="election-summary-timezone" className="font-medium break-all">
                {zone}
              </span>
            </p>
          </Panel>

          <Panel
            tone="settings"
            icon="ballot"
            title={t('elections.form.sections.settings')}
            id="election-settings-title"
            testId="election-card-settings"
            headerTestId="election-card-header-settings"
            bodyClassName="gap-1.5"
            className="min-[900px]:col-span-6 min-[1500px]:col-span-4"
          >
            <Setting
              name="language"
              icon="lang"
              tone="bg-primary-soft text-primary"
              label={t('elections.summary.language')}
              value={t(`elections.form.languages.${election.language}`)}
            />
            <Setting
              name="order"
              icon="shuffle"
              tone="bg-warm-soft text-warm"
              label={t('elections.summary.order')}
              value={t(`elections.form.order.${election.candidate_order}`)}
            />
            <Setting
              name="results"
              icon="eye"
              tone="bg-status-published-soft text-status-published"
              label={t('elections.summary.results')}
              value={t(`elections.form.results.${election.results_display}`)}
            />
          </Panel>

          <Panel
            tone="cover"
            icon="image"
            title={t('elections.form.sections.cover')}
            id="election-cover-card-title"
            testId="election-card-cover"
            headerTestId="election-card-header-cover"
            className="min-[900px]:col-span-6 min-[1500px]:col-span-3"
          >
            {election.cover ? (
              // The 960 px file: a plain image with its ratio set.
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={election.cover.md}
                alt={t('elections.cover.summaryAlt')}
                width={960}
                height={480}
                data-testid="election-summary-cover"
                className="h-44.5 w-full rounded-md bg-canvas object-cover"
              />
            ) : (
              <div className="bg-cover-empty cover-sweep flex h-44.5 w-full items-center justify-center rounded-md text-surface">
                <span aria-hidden="true" className="float-art relative z-10">
                  <Icon name="ballot" size={56} />
                </span>
              </div>
            )}
            {draft ? (
              <Link
                href={`/admin/elections/${election.id}/edit`}
                className={cx(
                  'ui-control lift-sm inline-flex h-10.5 w-fit items-center gap-2 rounded-full bg-primary-soft pr-4.5 pl-3 text-base font-medium text-status-scheduled hover:bg-primary-line',
                  focusRing,
                )}
              >
                <Icon name="edit" size={19} />
                {t('elections.summary.changeCover')}
              </Link>
            ) : null}
          </Panel>

          <Panel
            tone="facts"
            icon="people"
            title={t('elections.summary.ballots')}
            id="election-facts-title"
            testId="election-card-facts"
            headerTestId="election-card-header-facts"
            className="min-[900px]:col-span-6 min-[1500px]:col-span-4"
          >
            <div className="grid grid-cols-3 gap-2.5">
              {(
                [
                  ['positions', 'flag', election.ballots_count],
                  ['voters', 'people', election.voters_count],
                  // No vote count is ever shown before an election is closed (rule 6).
                  ['ballots', 'ballot', 0],
                ] as const
              ).map(([name, icon, value]) => (
                <div
                  key={name}
                  data-testid={`election-fact-${name}`}
                  className="flex min-w-0 flex-col gap-0.5 rounded-md bg-canvas p-3.5"
                >
                  <span className="text-primary">
                    <Icon name={icon} size={22} />
                  </span>
                  <b className="font-display text-[28px] leading-9 font-extrabold text-ink">
                    {value}
                  </b>
                  <span className="text-sm font-medium break-words text-ink-soft">
                    {t(`elections.summary.facts.${name}`)}
                  </span>
                </div>
              ))}
            </div>
            <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-line-soft px-3 py-1 text-xs font-bold text-status-draft">
              <Icon name="clock" size={15} />
              {t('elections.summary.factsSoon')}
            </span>
          </Panel>

          <div
            data-testid="election-card-steps"
            className="min-w-0 min-[900px]:col-span-12 min-[1500px]:col-span-8 [&>section]:h-full"
          >
            <NextSteps
              soon
              title={t('elections.summary.nextTitle')}
              headerTestId="election-card-header-steps"
              ballotsHref={`/admin/elections/${election.id}/ballots`}
              votersHref={`/admin/elections/${election.id}/voters`}
            />
          </div>
        </div>
      </section>

      {asking ? (
        <DeleteElectionDialog
          election={election}
          onCancel={() => setAsking(false)}
          onDeleted={() => router.push('/admin/elections')}
        />
      ) : null}
    </div>
  );
}
