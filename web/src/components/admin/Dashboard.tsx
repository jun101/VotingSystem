'use client';

import Link from 'next/link';
import { useMemo } from 'react';
import { rangeText } from '@/components/elections/rangeText';
import { STATUS_ICONS } from '@/components/elections/statusIcon';
import { StatusPill } from '@/components/elections/StatusPill';
import { GrowBar, LiveDot, Reveal } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { Election, ElectionCounts, ElectionStatus } from '@/lib/api/elections';
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
  roleCounts,
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
export function Dashboard({ now, elections, counts, institution, team, twoFactor }: DashboardData) {
  const user = useAdminUser();
  const { t, locale } = useI18n();
  const moment = useMemo(() => new Date(now), [now]);
  const zone = institution?.timezone ?? DEFAULT_TIME_ZONE;

  const start = gettingStarted({
    emailVerified: user.email_verified,
    hasLogo: Boolean(institution?.logo),
    hasElection: counts.all + counts.archived > 0,
  });
  const open = openElectionOf(elections);
  const todo = todoOf(elections, moment);
  const figures = figuresOf(elections, counts);
  const latest = latestOf(elections);
  const remaining = start.total - start.done;

  function when(row: (typeof todo)[number]): string {
    if (row.inDays <= 0) return t('admin.dashboard.todo.today');
    if (row.inDays === 1) return t('admin.dashboard.todo.tomorrow');

    return t('admin.dashboard.todo.inDays', { days: row.inDays });
  }

  return (
    <div data-testid="dashboard" className="flex flex-col gap-4">
      <section className="bg-hero cover-sweep flex flex-wrap items-center gap-x-6 gap-y-4 rounded-xl px-5 py-5 text-surface shadow-2 md:px-7">
        <div className="relative z-10 min-w-0">
          <h2 data-testid="dashboard-welcome" className="text-2xl text-surface md:text-3xl">
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
        className="rounded-2xl border border-line bg-surface p-3 shadow-1 2xl:p-5"
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

        <Reveal
          stagger
          className="grid grid-cols-[repeat(auto-fill,minmax(min(18.75rem,100%),1fr))] gap-4 2xl:grid-cols-[repeat(auto-fill,minmax(min(23.75rem,100%),1fr))]"
        >
          <DashCard
            card="open-election"
            icon="flag"
            title={t('admin.dashboard.openElection.title')}
            aside={
              open ? (
                <span className={ASIDE}>
                  <LiveDot />
                  {t('admin.dashboard.openElection.live')}
                </span>
              ) : null
            }
          >
            {open ? (
              <>
                <h4 className="font-display text-xl font-extrabold break-words text-ink">
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
                  className={cx(linkPrimary, 'mt-auto gap-2 self-start')}
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
                  className={cx(linkPrimary, 'gap-2')}
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
            aside={todo.length > 0 ? <span className={ASIDE}>{todo.length}</span> : null}
            className="gap-1"
          >
            {todo.length > 0 ? (
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
                className={cx(linkPrimary, 'mt-auto gap-2 self-start')}
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
                  <b className="relative font-display text-xl font-extrabold text-ink">
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
                <Link href="/admin/elections/new" className={cx(linkPrimary, 'gap-2')}>
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
                    ['voters', 'people', figures.voters],
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
          </DashCard>
        </Reveal>
      </section>
    </div>
  );
}
