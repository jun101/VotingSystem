'use client';

import Link from 'next/link';
import { useMemo, useState } from 'react';
import { rangeText } from '@/components/elections/rangeText';
import { STATUS_ICONS } from '@/components/elections/statusIcon';
import { StatusPill } from '@/components/elections/StatusPill';
import { GrowBar, LiveDot } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import { fetchDashboard } from '@/lib/api/browser';
import type { Dashboard as DashboardLive } from '@/lib/api/turnout';
import { usePolling } from '@/lib/usePolling';
import {
  ELECTION_STATUSES,
  type Election,
  type ElectionCounts,
  type ElectionStatus,
} from '@/lib/api/elections';
import type { InstitutionProfile, Listing, TeamMember, TwoFactorState } from '@/lib/api/user';
import { clock, longDay, pluralForm } from '@/lib/format/electionDates';
import { DEFAULT_TIME_ZONE } from '@/lib/format/timeZones';
import { useI18n } from '@/lib/i18n/client';
import { useAdminUser } from './AdminUser';
import { focusRing, linkPrimary } from './classes';
import { ASIDE, DashCard, Empty, Row, type Tone } from './DashCard';
import {
  elapsedShare,
  figuresOf,
  gettingStarted,
  latestOf,
  openElectionOf,
  openShown,
  roleCounts,
  timeLeft,
  todayText,
  todoOf,
  type StepKey,
} from './dashboardModel';
import { Icon, type IconName } from './Icon';

export type DashboardData = {
  /** The moment the page was made on the server, as an ISO text (so the page reads the same on the client). */
  now: string;
  elections: Election[];
  counts: ElectionCounts;
  institution: InstitutionProfile | null;
  /** The users, for an owner only. */
  team: Listing<TeamMember> | null;
  twoFactor: TwoFactorState | null;
  /** The live numbers of `GET /dashboard` (slice 14); null when the API did not answer. */
  live?: DashboardLive | null;
};

const STATUS_TONES: Record<ElectionStatus, Tone> = {
  draft: 'draft',
  scheduled: 'scheduled',
  open: 'open',
  closed: 'quiet',
  published: 'published',
  archived: 'quiet',
};

const STEP_ICONS: Record<StepKey, IconName> = {
  email: 'mail',
  institution: 'school',
  election: 'ballot',
};

const STEP_LINKS: Record<StepKey, string> = {
  email: '/admin/account',
  institution: '/admin/institution',
  election: '/admin/elections/new',
};

const QUICK =
  'ui-control lift-sm inline-flex h-12 items-center gap-2.5 rounded-full border pr-5 pl-3.5 text-md font-medium ' +
  focusRing;

function Fact({ icon, value, label }: { icon: IconName; value: number; label: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 rounded-md bg-canvas px-3 py-3">
      <span className="text-primary">
        <Icon name={icon} size={20} />
      </span>
      <b className="font-display text-2xl leading-8 font-extrabold text-ink">{value}</b>
      <span className="text-xs font-medium break-words text-ink-soft">{label}</span>
    </div>
  );
}

/**
 * The dashboard (FR-NAV-01): a welcome band with three quick actions, then one white zone
 * holding the cards, each in its coloured header. It shows what exists (the page reads it on
 * the server) and says honestly when a card is empty. No vote count appears here.
 */
export function Dashboard({
  now,
  elections,
  counts,
  institution,
  team,
  twoFactor,
  live: liveGiven = null,
}: DashboardData) {
  const user = useAdminUser();
  const { t, locale } = useI18n();
  const [live, setLive] = useState<DashboardLive | null>(liveGiven);
  const [stamp, setStamp] = useState<number | null>(null);
  const moment = useMemo(() => (stamp === null ? new Date(now) : new Date(stamp)), [now, stamp]);

  // While an election is open the live numbers are read again every 15 seconds; a failed read
  // keeps what is shown.
  usePolling(
    () => {
      void fetchDashboard().then(
        (fresh) => {
          setLive(fresh);
          setStamp(Date.now());
        },
        () => undefined,
      );
    },
    live !== null && live.open.length > 0,
  );
  const zone = institution?.timezone ?? DEFAULT_TIME_ZONE;

  const start = gettingStarted({
    emailVerified: user.email_verified,
    hasLogo: Boolean(institution?.logo),
    hasElection: counts.all + counts.archived > 0,
  });
  const open = openElectionOf(elections);
  const todo = todoOf(elections, moment);
  const figures = figuresOf(elections, counts);
  const openShownNow = live ? openShown(live.open) : null;
  const upcoming = live?.upcoming ?? [];

  /** The to-do rows: the API's (not voted, blockers, codes), then an election about to start. */
  type TodoItem = {
    key: string;
    href: string;
    icon: IconName;
    tone: Tone;
    title: string;
    detail: string;
  };

  const todoItems: TodoItem[] = live
    ? [
        ...live.todo.map((item): TodoItem => {
          const base = `/admin/elections/${item.election.id}`;

          return {
            key: `${item.kind}-${item.election.id}`,
            href:
              item.kind === 'voters_not_voted'
                ? `${base}/turnout`
                : item.kind === 'codes_missing'
                  ? `${base}/codes`
                  : `${base}/edit`,
            icon:
              item.kind === 'voters_not_voted'
                ? 'people'
                : item.kind === 'codes_missing'
                  ? 'qr'
                  : 'draft',
            tone: item.kind === 'voters_not_voted' ? 'primary' : 'warm',
            title: t(
              `admin.dashboard.live.todoKinds.${item.kind}.${pluralForm(locale, item.count)}`,
              { count: item.count },
            ),
            detail: item.election.title,
          };
        }),
        ...todo
          .filter((row) => row.kind === 'soon')
          .map((row): TodoItem => ({
            key: `soon-${row.election.id}`,
            href: `/admin/elections/${row.election.id}`,
            icon: 'clock',
            tone: 'primary',
            title: t('admin.dashboard.todo.soon'),
            detail: t('admin.dashboard.todo.detail', {
              title: row.election.title,
              when: when(row),
            }),
          })),
      ]
    : [];
  const todoCount = live ? todoItems.length : todo.length;
  const latest = latestOf(elections);
  const remaining = start.total - start.done;

  function when(row: (typeof todo)[number]): string {
    if (row.inDays <= 0) return t('admin.dashboard.todo.today');
    if (row.inDays === 1) return t('admin.dashboard.todo.tomorrow');

    return t('admin.dashboard.todo.inDays', { days: row.inDays });
  }

  return (
    <div data-testid="dashboard" className="flex flex-col gap-4">
      <section className="bg-hero cover-sweep flex flex-wrap items-center gap-x-6 gap-y-4 rounded-xl px-5 py-5.5 text-surface shadow-2 md:px-7">
        <div className="relative z-10 min-w-0">
          <h2 data-testid="dashboard-welcome" className="text-2xl text-surface md:text-[30px]">
            {t('admin.dashboard.welcome', { name: user.name })}
          </h2>
          {user.institution ? (
            <p data-testid="dashboard-institution" className="mt-1 text-lg text-surface">
              {t('admin.dashboard.institutionRole', {
                name: user.institution.name,
                role: t(`admin.roles.${user.role}`),
              })}
            </p>
          ) : null}
        </div>
        <div className="relative z-10 flex flex-wrap gap-2.5 md:ml-auto">
          <Link
            href="/admin/elections/new"
            data-testid="dashboard-quick-new-election"
            className={cx(QUICK, 'border-accent bg-accent font-bold text-deep')}
          >
            <Icon name="plus" size={22} />
            {t('admin.dashboard.quick.newElection')}
          </Link>
          <Link
            href="/admin/institution"
            data-testid="dashboard-quick-invite"
            className={cx(QUICK, 'border-glass-line bg-glass text-surface hover:bg-glass-line')}
          >
            <Icon name="people" size={22} />
            {t('admin.dashboard.quick.invite')}
          </Link>
          <Link
            href="/admin/institution"
            data-testid="dashboard-quick-institution"
            className={cx(QUICK, 'border-glass-line bg-glass text-surface hover:bg-glass-line')}
          >
            <Icon name="school" size={22} />
            {t('admin.dashboard.quick.institution')}
          </Link>
        </div>
      </section>

      <section
        data-testid="dashboard-zone"
        aria-labelledby="dashboard-zone-title"
        className="rounded-2xl border border-line bg-surface p-5 shadow-1"
      >
        <div className="mx-1 mb-4 flex flex-wrap items-center gap-x-3">
          <span className="text-primary">
            <Icon name="pulse" size={24} />
          </span>
          <h2 id="dashboard-zone-title" className="text-xl">
            {t('admin.dashboard.zone.title')}
          </h2>
          <small className="text-base font-medium text-ink-soft">
            {t('admin.dashboard.zone.today', { date: todayText(moment, locale, zone) })}
          </small>
        </div>

        <div className="rise-stagger grid grid-cols-[repeat(auto-fill,minmax(min(18.75rem,100%),1fr))] gap-4.5 2xl:grid-cols-[repeat(auto-fill,minmax(min(23.75rem,100%),1fr))]">
          <DashCard
            card="open-election"
            icon="flag"
            title={t('admin.dashboard.openElection.title')}
            aside={
              (live ? live.open.length > 0 : open) ? (
                <span className={ASIDE}>
                  <LiveDot />
                  {t('admin.dashboard.openElection.live')}
                </span>
              ) : null
            }
          >
            {live && live.open.length > 0 && openShownNow ? (
              <>
                {openShownNow.shown.map((item, index) => {
                  const left = timeLeft(item.ends_at, moment.getTime());
                  const leftText =
                    left.kind === 'over'
                      ? t('admin.dashboard.live.over')
                      : t('admin.dashboard.live.left', {
                          time: t(
                            left.kind === 'days'
                              ? 'admin.dashboard.live.leftDays'
                              : left.kind === 'hours'
                                ? 'admin.dashboard.live.leftHours'
                                : 'admin.dashboard.live.leftMinutes',
                            { days: left.days, hours: left.hours, minutes: left.minutes },
                          ),
                        });
                  const full = elections.find((one) => one.id === item.id);
                  const n = index + 1;

                  return (
                    <div
                      key={item.id}
                      data-testid={`dashboard-election-${n}`}
                      className="flex flex-col gap-3 rounded-md border border-line bg-surface-alt p-3.5"
                    >
                      <h4 className="font-display text-[19px] leading-tight font-extrabold break-words text-ink">
                        {item.title}
                      </h4>
                      <p className="flex items-center gap-2 text-base text-ink-soft">
                        <span aria-hidden="true" className="text-primary">
                          <Icon name="clock" size={18} />
                        </span>
                        <span data-testid={`dashboard-election-left-${n}`}>{leftText}</span>
                        <span aria-hidden="true">{'\u00b7'}</span>
                        <span>
                          {t('admin.dashboard.openElection.ends', {
                            day: longDay(new Date(item.ends_at), locale, full?.timezone ?? zone),
                            time: clock(new Date(item.ends_at), locale, full?.timezone ?? zone),
                          })}
                        </span>
                      </p>
                      <div data-testid={`dashboard-turnout-${n}`}>
                        <div className="mb-1.5 flex items-baseline justify-between gap-3">
                          <span className="font-display text-2xl leading-8 font-extrabold text-ink">
                            {t('admin.dashboard.live.percent', {
                              percent: new Intl.NumberFormat(locale, {
                                maximumFractionDigits: 1,
                              }).format(item.percentage),
                            })}
                          </span>
                          <span className="text-base text-ink-soft">
                            {t('admin.dashboard.live.votedOf', {
                              voted: item.voted,
                              eligible: item.eligible,
                            })}
                          </span>
                        </div>
                        <GrowBar
                          value={item.eligible > 0 ? item.voted / item.eligible : 0}
                          trackClassName="h-3"
                          className="bg-bar-open"
                        />
                      </div>
                      <div className="flex flex-wrap gap-2.5">
                        <Link
                          href={`/admin/elections/${item.id}/turnout`}
                          data-testid={`dashboard-election-turnout-${n}`}
                          className={cx(linkPrimary, 'min-h-11.5 gap-2 px-5')}
                        >
                          <Icon name="chart" size={20} />
                          {t('admin.dashboard.live.viewTurnout')}
                        </Link>
                        <Link
                          href={`/admin/elections/${item.id}`}
                          data-testid={`dashboard-election-open-${n}`}
                          className={cx(
                            'ui-control inline-flex min-h-11.5 items-center gap-2 rounded-full border border-line-strong bg-surface px-5 text-base font-semibold text-ink hover:bg-primary-soft',
                            focusRing,
                          )}
                        >
                          <Icon name="open" size={20} />
                          {t('admin.dashboard.live.viewElection')}
                        </Link>
                      </div>
                    </div>
                  );
                })}
                {openShownNow.more > 0 ? (
                  <Link
                    href="/admin/elections?status=open"
                    className={cx(
                      'ui-control inline-flex min-h-11 items-center gap-2 self-start rounded-full px-3 text-base font-bold text-primary-hover hover:bg-primary-soft',
                      focusRing,
                    )}
                  >
                    {t('admin.dashboard.live.more', { count: live.open.length })}
                    <Icon name="open" size={16} />
                  </Link>
                ) : null}
              </>
            ) : !live && open ? (
              <>
                <h4 className="font-display text-[21px] font-extrabold break-words text-ink">
                  {open.title}
                </h4>
                <p className="text-base text-ink-soft">
                  {t('admin.dashboard.openElection.ends', {
                    day: longDay(new Date(open.ends_at), locale, open.timezone),
                    time: clock(new Date(open.ends_at), locale, open.timezone),
                  })}
                </p>
                <div>
                  <div className="mb-1.5 flex justify-between text-base">
                    <span className="text-ink-soft">
                      {t('admin.dashboard.openElection.elapsed')}
                    </span>
                  </div>
                  <GrowBar
                    value={elapsedShare(open, moment)}
                    trackClassName="h-3"
                    className="bg-bar-open"
                  />
                </div>
                <div className="grid grid-cols-3 gap-2">
                  <Fact
                    icon="flag"
                    value={open.ballots_count}
                    label={t('admin.dashboard.openElection.positions')}
                  />
                  <Fact
                    icon="people"
                    value={open.voters_count}
                    label={t('admin.dashboard.openElection.voters')}
                  />
                  {/* No vote count before the election is closed (rule 6). */}
                  <Fact icon="ballot" value={0} label={t('admin.dashboard.openElection.ballots')} />
                </div>
                <Link
                  href={`/admin/elections/${open.id}`}
                  className={cx(linkPrimary, 'mt-auto min-h-11.5 gap-2 self-start px-5.5')}
                >
                  <Icon name="open" size={20} />
                  {t('admin.dashboard.openElection.open')}
                </Link>
              </>
            ) : (
              <Empty icon="flag" tone="open" text={t('admin.dashboard.openElection.empty')}>
                <Link
                  href="/admin/elections/new"
                  data-testid="dashboard-create-election"
                  className={cx(linkPrimary, 'min-h-11.5 gap-2 px-5.5')}
                >
                  <Icon name="plus" size={20} />
                  {t('admin.dashboard.create')}
                </Link>
              </Empty>
            )}
          </DashCard>

          <DashCard
            card="todo"
            icon="list"
            title={t('admin.dashboard.todo.title')}
            aside={todoCount > 0 ? <span className={ASIDE}>{todoCount}</span> : null}
            className="gap-1"
          >
            {live && todoItems.length > 0 ? (
              todoItems.map((item, index) => (
                <Row
                  key={item.key}
                  data-testid={`dashboard-todo-${index + 1}`}
                  href={item.href}
                  icon={item.icon}
                  tone={item.tone}
                  title={item.title}
                  detail={item.detail}
                />
              ))
            ) : !live && todo.length > 0 ? (
              todo.map((row, index) => (
                <Row
                  key={row.election.id}
                  data-testid={`dashboard-todo-${index + 1}`}
                  href={
                    row.kind === 'draft'
                      ? `/admin/elections/${row.election.id}/edit`
                      : `/admin/elections/${row.election.id}`
                  }
                  icon={row.kind === 'draft' ? 'draft' : 'clock'}
                  tone={row.kind === 'draft' ? 'warm' : 'primary'}
                  title={t(
                    row.kind === 'draft'
                      ? 'admin.dashboard.todo.draft'
                      : 'admin.dashboard.todo.soon',
                  )}
                  detail={
                    row.kind === 'draft'
                      ? row.election.title
                      : t('admin.dashboard.todo.detail', {
                          title: row.election.title,
                          when: when(row),
                        })
                  }
                />
              ))
            ) : (
              <Empty icon="check" tone="warm" text={t('admin.dashboard.todo.empty')} />
            )}
          </DashCard>

          {start.complete ? (
            <DashCard
              card="institution"
              icon="school"
              title={t('admin.dashboard.institutionCard.title')}
            >
              {team ? (
                <Row
                  icon="people"
                  tone="primary"
                  title={t(
                    `admin.dashboard.institutionCard.users.${pluralForm(locale, team.total)}`,
                    { count: team.total },
                  )}
                  detail={t('admin.dashboard.institutionCard.breakdown', roleCounts(team.items))}
                />
              ) : null}
              {twoFactor ? (
                <Row
                  icon="log"
                  tone="teal"
                  title={t('admin.dashboard.institutionCard.twoFactor')}
                  detail={t(
                    twoFactor.enabled
                      ? 'admin.dashboard.institutionCard.twoFactorOn'
                      : 'admin.dashboard.institutionCard.twoFactorOff',
                  )}
                />
              ) : null}
              <Link
                href="/admin/institution"
                className={cx(linkPrimary, 'mt-auto min-h-11.5 gap-2 self-start px-5.5')}
              >
                <Icon name="people" size={20} />
                {t('admin.dashboard.institutionCard.invite')}
              </Link>
            </DashCard>
          ) : (
            <DashCard
              card="getting-started"
              icon="rocket"
              title={t('admin.dashboard.gettingStarted.title')}
              aside={
                <span className={ASIDE}>
                  {t('admin.dashboard.gettingStarted.count', {
                    done: start.done,
                    total: start.total,
                  })}
                </span>
              }
              className="gap-1.5"
            >
              <div className="mb-1.5 flex items-center gap-4">
                <div
                  data-testid="dashboard-progress"
                  role="progressbar"
                  aria-label={t('admin.dashboard.gettingStarted.progress')}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-valuenow={start.percent}
                  className="ring-spin relative grid size-21 shrink-0 place-items-center rounded-full"
                  style={{
                    backgroundImage: `conic-gradient(var(--color-primary) 0 ${start.percent}%, var(--color-primary-soft) 0)`,
                  }}
                >
                  <span aria-hidden="true" className="absolute size-15.5 rounded-full bg-surface" />
                  <b className="relative font-display text-[20px] font-extrabold text-ink">
                    {t('admin.dashboard.gettingStarted.percent', { percent: start.percent })}
                  </b>
                </div>
                <p className="text-base text-ink-soft">
                  {t(`admin.dashboard.gettingStarted.lead.${pluralForm(locale, remaining)}`, {
                    count: remaining,
                  })}
                </p>
              </div>
              {start.steps.map((step) => (
                <Row
                  key={step.key}
                  data-testid={`dashboard-step-${step.key}`}
                  data-done={step.done ? 'true' : 'false'}
                  href={STEP_LINKS[step.key]}
                  icon={step.done ? 'check' : STEP_ICONS[step.key]}
                  tone={step.done ? 'open' : 'primary'}
                  struck={step.done}
                  title={t(`admin.dashboard.gettingStarted.steps.${step.key}.title`)}
                  detail={
                    step.done
                      ? t('admin.dashboard.gettingStarted.done')
                      : t(`admin.dashboard.gettingStarted.steps.${step.key}.hint`)
                  }
                />
              ))}
            </DashCard>
          )}

          <DashCard card="activity" icon="pulse" title={t('admin.dashboard.activity.title')}>
            <Empty icon="pulse" tone="published" text={t('admin.dashboard.activity.empty')} />
          </DashCard>

          <DashCard
            card="latest"
            icon="ballot"
            title={t('admin.dashboard.latest.title')}
            aside={
              latest.length > 0 ? (
                <Link href="/admin/elections" className={cx(ASIDE, focusRing)}>
                  {t('admin.dashboard.latest.all')}
                  <Icon name="open" size={14} />
                </Link>
              ) : null
            }
            className="gap-1"
          >
            {latest.length > 0 ? (
              latest.map((election, index) => (
                <Row
                  key={election.id}
                  data-testid={`dashboard-latest-${index + 1}`}
                  href={`/admin/elections/${election.id}`}
                  icon={STATUS_ICONS[election.status]}
                  tone={STATUS_TONES[election.status]}
                  title={election.title}
                  detail={rangeText(election, locale, t)}
                  end={<StatusPill status={election.status} className="ml-auto shrink-0" />}
                />
              ))
            ) : (
              <Empty icon="ballot" tone="primary" text={t('admin.dashboard.latest.empty')}>
                <Link
                  href="/admin/elections/new"
                  className={cx(linkPrimary, 'min-h-11.5 gap-2 px-5.5')}
                >
                  <Icon name="plus" size={20} />
                  {t('admin.dashboard.quick.newElection')}
                </Link>
              </Empty>
            )}
          </DashCard>

          <DashCard card="figures" icon="chart" title={t('admin.dashboard.figures.title')}>
            {counts.all > 0 ? (
              <div className="grid grid-cols-3 gap-2">
                {(
                  [
                    ['elections', 'ballot', figures.elections],
                    ['voters', 'people', live ? live.voters : figures.voters],
                    ['ballots', 'check', figures.ballots],
                  ] as const
                ).map(([key, icon, value]) => (
                  <div
                    key={key}
                    className="flex min-w-0 flex-col gap-0.5 rounded-md bg-canvas px-3 py-3"
                  >
                    <span className="text-primary">
                      <Icon name={icon} size={20} />
                    </span>
                    <b
                      data-testid={`dashboard-figure-${key}`}
                      className="font-display text-2xl leading-8 font-extrabold text-ink"
                    >
                      {value}
                    </b>
                    <span className="text-xs font-medium break-words text-ink-soft">
                      {t(`admin.dashboard.figures.${key}`)}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <Empty icon="chart" tone="teal" text={t('admin.dashboard.figures.empty')} />
            )}
            {live && counts.all > 0 ? (
              <div data-testid="dashboard-counts" className="flex flex-col gap-2">
                <span className="text-xs font-bold tracking-wider text-ink-soft uppercase">
                  {t('admin.dashboard.live.counts')}
                </span>
                <ul className="flex flex-wrap gap-2">
                  {ELECTION_STATUSES.map((status) => (
                    <li
                      key={status}
                      data-testid={`dashboard-count-${status}`}
                      className="inline-flex items-center gap-2"
                    >
                      <StatusPill status={status} />
                      <b className="text-md text-ink">{live.counts[status]}</b>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </DashCard>

          {upcoming.length > 0 ? (
            <DashCard
              card="upcoming"
              icon="calendar"
              title={t('admin.dashboard.live.upcoming.title')}
              aside={<span className={ASIDE}>{upcoming.length}</span>}
              className="gap-1"
            >
              {upcoming.map((item, index) => (
                <Row
                  key={item.id}
                  data-testid={`dashboard-upcoming-${index + 1}`}
                  href={`/admin/elections/${item.id}`}
                  icon="calendar"
                  tone="scheduled"
                  title={item.title}
                  detail={t('admin.dashboard.live.upcoming.starts', {
                    day: longDay(new Date(item.starts_at), locale, zone),
                    time: clock(new Date(item.starts_at), locale, zone),
                  })}
                />
              ))}
            </DashCard>
          ) : null}
        </div>
      </section>
    </div>
  );
}
