'use client';

import Link from 'next/link';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing, linkPrimary } from '@/components/admin/classes';
import { saveBlob } from '@/components/codes/saveFile';
import { Panel } from '@/components/elections/Panel';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { CountUp, GrowBar, LiveDot, Reveal } from '@/components/motion';
import { Input, Notice, Select } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { SEARCH_MAX } from '@/components/voters/voterQuery';
import { initialsOf, pageCount, toneOf } from '@/components/voters/voterText';
import { downloadVoterExport, fetchTurnout, fetchVoters } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import type { Turnout, TurnoutBucket } from '@/lib/api/turnout';
import type { GroupList, VoterList } from '@/lib/api/voters';
import { clock, longDay, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { usePolling } from '@/lib/usePolling';
import { TurnoutChart } from './TurnoutChart';
import { groupLines, percentText, share } from './turnoutModel';

/** The text colour of the badge on the header band, by status. */
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
  'ui-control inline-flex h-12 items-center justify-center gap-2 rounded-full border border-glass-line bg-glass pr-5 pl-4 text-md font-semibold text-surface hover:bg-glass-line max-md:w-full ' +
  focusRing;

const PAGE_BUTTON =
  'ui-control inline-flex size-11 items-center justify-center rounded-full border border-line-strong bg-surface text-ink md:size-9 hover:bg-primary-soft disabled:opacity-40 ' +
  focusRing;

const EXPORT_BUTTON =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-primary bg-primary pr-5 pl-4 text-base font-semibold text-surface shadow-button hover:bg-primary-hover disabled:opacity-60 ' +
  focusRing;

const EXPORT_SECONDARY =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line-strong bg-surface pr-5 pl-4 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft disabled:opacity-60 ' +
  focusRing;

const AVATAR_TONES = [
  'bg-primary-soft text-primary-hover',
  'bg-teal-soft text-teal-ink',
  'bg-warm-soft text-warm-ink',
  'bg-status-published-soft text-status-published',
] as const;

const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

/** The search is sent when typing stops for this long. */
const DEBOUNCE_MS = 300;

type ListFilters = { page: number; q: string; group: string };

/** One of the three tiles under the headline figure. */
function Fact({
  icon,
  testId,
  value,
  label,
  locale,
}: {
  icon: 'people' | 'check' | 'clock';
  testId: string;
  value: number;
  label: string;
  locale: 'fr' | 'en';
}) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 rounded-md bg-glass px-3.5 py-3">
      <span aria-hidden="true">
        <Icon name={icon} size={20} />
      </span>
      <b data-testid={testId} className="font-display text-3xl leading-9 font-extrabold">
        <CountUp value={value} locale={locale} duration={900} />
      </b>
      <span className="text-sm font-medium break-words">{label}</span>
    </div>
  );
}

/**
 * Screen A12: the turnout of one election, in total, by group and over time, and who has not
 * voted (with search, group, and an Excel or CSV export). Only participation is shown: nothing
 * here asks for, or knows, what anyone voted. While the election is open the page reads the
 * numbers again every 15 seconds.
 */
export function TurnoutPage({
  election,
  initialTurnout,
  initialVoters,
  groups,
}: {
  election: Election;
  initialTurnout: Turnout;
  initialVoters: VoterList;
  groups: GroupList;
}) {
  const { t, tIfAny, locale } = useI18n();
  // Polling stops at the end instant too, not only on the status the page was made with: the status
  // changes on the server a little after the end, and the page is not reloaded.
  const [over, setOver] = useState(false);
  const live = election.status === 'open' && !over;

  const [turnout, setTurnout] = useState<Turnout>(initialTurnout);
  const [list, setList] = useState<VoterList>(initialVoters);
  const [filters, setFilters] = useState<ListFilters>({ page: 1, q: '', group: '' });
  const [searchText, setSearchText] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [exporting, setExporting] = useState<'xlsx' | 'csv' | null>(null);
  const [exportProblem, setExportProblem] = useState<string | null>(null);

  // The filters the shown list was read for, the newest filters and bucket, and the newest read.
  const loaded = useRef<ListFilters>(filters);
  const current = useRef<ListFilters>(filters);
  const bucketRef = useRef<TurnoutBucket | undefined>(undefined);
  const ticket = useRef(0);
  // The newest read of the numbers: a bucket chosen while a poll is in flight must not be undone by it.
  const numbersTicket = useRef(0);
  const alive = useRef(true);

  useEffect(() => {
    current.current = filters;
  });

  useEffect(() => {
    alive.current = true;

    return () => {
      alive.current = false;
    };
  }, []);

  function listQuery(wanted: ListFilters) {
    return {
      page: wanted.page,
      q: wanted.q,
      group: wanted.group,
      credential: '',
      email: '',
      voted: 'no',
    } as const;
  }

  function failed(caught: unknown) {
    const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

    setProblem(`${t('turnout.loadFailed')} ${errorText(error, tIfAny)}`);
  }

  /** The list for these filters; a page past the end moves to the last one. */
  async function loadList(wanted: ListFilters) {
    const mine = ++ticket.current;

    try {
      const next = await fetchVoters(election.id, listQuery(wanted));

      if (mine !== ticket.current || !alive.current) return;

      const last = pageCount(next.total);

      if (wanted.page > last) {
        setFilters({ ...wanted, page: last });

        return;
      }

      loaded.current = wanted;
      setList(next);
      setProblem(null);
    } catch (caught) {
      if (mine === ticket.current && alive.current) failed(caught);
    }
  }

  /** The numbers and the list, read again (the 15-second poll). */
  async function refresh() {
    const wanted = current.current;
    const mine = ++ticket.current;
    const numbersMine = ++numbersTicket.current;

    try {
      const [numbers, voters] = await Promise.all([
        fetchTurnout(election.id, bucketRef.current),
        fetchVoters(election.id, listQuery(wanted)),
      ]);

      if (!alive.current) return;
      if (numbersMine === numbersTicket.current) setTurnout(numbers);
      if (mine !== ticket.current) return;

      const last = pageCount(voters.total);

      if (wanted.page > last) {
        setFilters({ ...wanted, page: last });

        return;
      }

      loaded.current = wanted;
      setList(voters);
      setProblem(null);
    } catch (caught) {
      if (mine === ticket.current && alive.current) failed(caught);
    }
  }

  usePolling(() => void refresh(), live);

  // At the end instant: stop polling, change the chip, and read once more for the final numbers.
  useEffect(() => {
    if (election.status !== 'open') return;

    const endsAt = new Date(election.ends_at).getTime();
    let timer = 0;

    const arm = () => {
      const wait = endsAt - Date.now();

      if (wait <= 0) {
        setOver(true);
        void refresh();

        return;
      }

      // A timer holds at most about 24 days: longer waits are cut and armed again.
      timer = window.setTimeout(arm, Math.min(wait, 2_000_000_000));
    };

    arm();

    return () => window.clearTimeout(timer);
    // `refresh` only reads refs and the render's election.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [election.status, election.ends_at]);

  // A change of filters (a group, a page, the search) reads the list.
  useEffect(() => {
    if (
      filters.page === loaded.current.page &&
      filters.q === loaded.current.q &&
      filters.group === loaded.current.group
    ) {
      return;
    }

    void loadList(filters);
    // `loadList` only reads what the render gave it and refs.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters]);

  // The search is applied when typing stops.
  useEffect(() => {
    const text = searchText.trim();

    if (text === current.current.q) return;

    const timer = window.setTimeout(() => {
      setFilters((now) => ({ ...now, q: text, page: 1 }));
    }, DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [searchText]);

  async function chooseBucket(bucket: TurnoutBucket) {
    if (bucket === turnout.bucket) return;

    bucketRef.current = bucket;
    // A poll already in flight is now stale: it asked for the old bucket.
    const mine = ++numbersTicket.current;

    try {
      const numbers = await fetchTurnout(election.id, bucket);

      if (alive.current && mine === numbersTicket.current) {
        setTurnout(numbers);
        setProblem(null);
      }
    } catch (caught) {
      if (alive.current && mine === numbersTicket.current) failed(caught);
    }
  }

  function chooseGroup(group: string) {
    setFilters((now) => ({ ...now, group, page: 1 }));
  }

  function clearFilters() {
    setSearchText('');
    setFilters({ page: 1, q: '', group: '' });
  }

  async function download(format: 'xlsx' | 'csv') {
    setExporting(format);
    setExportProblem(null);

    try {
      const file = await downloadVoterExport(election.id, {
        format,
        voted: 'no',
        group: filters.group,
        q: filters.q,
      });

      saveBlob(file.blob, file.name);
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (alive.current) {
        setExportProblem(`${t('turnout.nonvoters.exportFailed')} ${errorText(error, tIfAny)}`);
      }
    }

    if (alive.current) setExporting(null);
  }

  const lines = useMemo(() => groupLines(turnout.by_group), [turnout.by_group]);
  const left = Math.max(0, turnout.eligible - turnout.voted);
  const none = turnout.eligible === 0;
  const filtering = filters.q !== '' || filters.group !== '';
  const pages = pageCount(list.total);
  const from = (list.page - 1) * 24 + 1;
  const to = Math.min(list.total, list.page * 24);
  const end = new Date(election.ends_at);
  const zone = election.timezone;
  const state = election.status;
  const statusNote =
    state === 'open'
      ? over
        ? t('turnout.final')
        : null
      : state === 'draft' || state === 'scheduled'
        ? t('turnout.notStarted')
        : t('turnout.final');

  return (
    <div data-testid="turnout-page" className="flex flex-col gap-4.5">
      <Link
        href={`/admin/elections/${election.id}`}
        data-testid="turnout-back"
        className={cx(
          'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink',
          focusRing,
        )}
      >
        <Icon name="left" size={18} />
        {t('turnout.back')}
      </Link>

      <section
        data-testid="turnout-hero"
        data-status={election.status}
        aria-labelledby="turnout-title"
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
            {live ? <LiveDot /> : <Icon name={STATUS_ICONS[election.status]} size={16} />}
            <span data-status={election.status}>{t(`elections.status.${election.status}`)}</span>
          </span>
          <h2
            id="turnout-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('turnout.title')}
          </h2>
          <p className="line-clamp-2 text-md break-words text-surface">{election.title}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span data-testid="turnout-chip-live" className={CHIP}>
              <Icon name={live ? 'pulse' : 'check'} size={18} />
              {live ? t('turnout.live') : (statusNote ?? '')}
            </span>
          </div>
        </div>
        <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
          <Link href={`/admin/elections/${election.id}/voters`} className={GLASS_LINK}>
            <Icon name="people" size={18} />
            {t('turnout.voters')}
          </Link>
        </div>
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="turnout-notice">
          {problem}
        </Notice>
      ) : null}

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <section
          data-testid="turnout-zone"
          aria-label={t('turnout.zone')}
          className="flex min-w-0 flex-1 flex-col gap-4.5 rounded-2xl border border-line bg-surface p-2.5 shadow-1 md:p-3 2xl:p-5"
        >
          <Reveal>
            <section
              aria-labelledby="turnout-headline-title"
              className="bg-hero cover-sweep relative flex flex-col gap-4 rounded-lg p-5 text-surface shadow-2 md:p-6"
            >
              <div className="relative z-10 flex flex-wrap items-baseline gap-x-5 gap-y-2">
                <h3 id="turnout-headline-title" className="sr-only">
                  {t('turnout.headline.label')}
                </h3>
                <span aria-hidden="true" className="text-md font-semibold">
                  {t('turnout.headline.label')}
                </span>
                <b
                  data-testid="turnout-percentage"
                  className="font-display text-[64px] leading-none font-extrabold md:text-[76px]"
                >
                  {t('turnout.headline.percent', {
                    percent: percentText(turnout.percentage, locale),
                  })}
                </b>
                <span className="text-lg">
                  {t(`turnout.headline.of.${pluralForm(locale, turnout.eligible)}`, {
                    voted: turnout.voted,
                    eligible: turnout.eligible,
                  })}
                </span>
              </div>
              <div className="relative z-10">
                <div
                  role="img"
                  aria-label={t('turnout.headline.percent', {
                    percent: percentText(turnout.percentage, locale),
                  })}
                  className="rounded-full"
                >
                  <GrowBar
                    value={share(turnout.voted, turnout.eligible)}
                    trackClassName="h-3"
                    className="bg-bar-open"
                  />
                </div>
              </div>
              <div className="relative z-10 grid grid-cols-1 gap-2.5 min-[480px]:grid-cols-3">
                <Fact
                  icon="people"
                  testId="turnout-eligible"
                  value={turnout.eligible}
                  label={t('turnout.eligible')}
                  locale={locale}
                />
                <Fact
                  icon="check"
                  testId="turnout-voted"
                  value={turnout.voted}
                  label={t('turnout.voted')}
                  locale={locale}
                />
                <Fact
                  icon="clock"
                  testId="turnout-left"
                  value={left}
                  label={t('turnout.left')}
                  locale={locale}
                />
              </div>
            </section>
          </Reveal>

          {none ? (
            <div
              data-testid="turnout-empty"
              className="flex flex-col items-center gap-3 rounded-lg border-2 border-dashed border-primary-line bg-surface-alt px-4 py-10 text-center"
            >
              <span
                aria-hidden="true"
                className="flex size-16 items-center justify-center rounded-full bg-primary text-surface shadow-button"
              >
                <Icon name="people" size={30} />
              </span>
              <h3 className="font-display text-lg font-extrabold text-ink">
                {t('turnout.empty.title')}
              </h3>
              <p className="max-w-md text-base text-ink-soft">{t('turnout.empty.text')}</p>
              <Link
                href={`/admin/elections/${election.id}/voters`}
                className={cx(linkPrimary, 'gap-2')}
              >
                <Icon name="people" size={18} />
                {t('turnout.empty.action')}
              </Link>
            </div>
          ) : null}

          <Panel
            tone="calendar"
            icon="chart"
            title={t('turnout.chart.title')}
            id="turnout-chart-title"
            testId="turnout-chart-card"
            aside={
              <div
                role="group"
                aria-label={t('turnout.chart.bucketLabel')}
                className="flex gap-1 rounded-full bg-glass-line p-1"
              >
                {(['hour', 'day'] as const).map((bucket) => (
                  <button
                    key={bucket}
                    type="button"
                    data-testid={`turnout-bucket-${bucket}`}
                    aria-pressed={turnout.bucket === bucket}
                    onClick={() => void chooseBucket(bucket)}
                    className={cx(
                      'ui-control inline-flex min-h-8 items-center rounded-full px-3 text-sm font-bold focus-visible:outline-2 focus-visible:outline-surface',
                      turnout.bucket === bucket ? 'bg-surface text-primary-hover' : 'text-surface',
                    )}
                  >
                    {t(`turnout.chart.${bucket}`)}
                  </button>
                ))}
              </div>
            }
          >
            <TurnoutChart points={turnout.over_time} bucket={turnout.bucket} zone={zone} />
          </Panel>

          <Panel
            tone="steps"
            icon="people"
            title={t('turnout.nonvoters.title')}
            id="nonvoters-title"
            testId="nonvoters-card"
            aside={
              <span className="inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap">
                {t('turnout.nonvoters.count', { count: left })}
              </span>
            }
          >
            {!none ? (
              <div className="flex flex-col gap-3">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  <Input
                    type="search"
                    label={t('turnout.nonvoters.search')}
                    value={searchText}
                    onChange={(event) => setSearchText(event.target.value)}
                    maxLength={SEARCH_MAX}
                    autoComplete="off"
                    enterKeyHint="search"
                    data-testid="nonvoters-search"
                  />
                  <Select
                    label={t('turnout.nonvoters.group')}
                    value={filters.group}
                    onChange={(event) => chooseGroup(event.target.value)}
                    data-testid="nonvoters-group"
                  >
                    <option value="">{t('turnout.nonvoters.groupAll')}</option>
                    {groups.items.map((group) => (
                      <option key={group.id} value={group.id}>
                        {group.name}
                      </option>
                    ))}
                    <option value="none">{t('turnout.nonvoters.groupNone')}</option>
                  </Select>
                </div>
                <div
                  role="group"
                  aria-label={t('turnout.nonvoters.export')}
                  className="flex flex-wrap gap-2.5"
                >
                  <button
                    type="button"
                    data-testid="nonvoters-export-xlsx"
                    disabled={exporting !== null}
                    aria-busy={exporting === 'xlsx' || undefined}
                    onClick={() => void download('xlsx')}
                    className={EXPORT_BUTTON}
                  >
                    <Icon path={DOWNLOAD_PATH} size={18} />
                    {t('turnout.nonvoters.exportXlsx')}
                  </button>
                  <button
                    type="button"
                    data-testid="nonvoters-export-csv"
                    disabled={exporting !== null}
                    aria-busy={exporting === 'csv' || undefined}
                    onClick={() => void download('csv')}
                    className={EXPORT_SECONDARY}
                  >
                    <Icon path={DOWNLOAD_PATH} size={18} />
                    {t('turnout.nonvoters.exportCsv')}
                  </button>
                </div>
                {exportProblem ? (
                  <Notice tone="danger" role="alert" data-testid="nonvoters-export-error">
                    {exportProblem}
                  </Notice>
                ) : null}
              </div>
            ) : null}

            {list.items.length > 0 ? (
              <ul
                aria-label={t('turnout.nonvoters.list')}
                data-testid="nonvoters-list"
                className="grid grid-cols-1 gap-3 md:grid-cols-2 min-[2300px]:grid-cols-3"
              >
                {list.items.map((voter, index) => (
                  <li
                    key={voter.id}
                    className="flex h-16 min-w-0 items-center gap-3 rounded-lg border border-line bg-surface p-3 shadow-1"
                  >
                    <span
                      aria-hidden="true"
                      className={cx(
                        'flex size-10 shrink-0 items-center justify-center rounded-full font-display text-base font-extrabold',
                        AVATAR_TONES[toneOf(voter.full_name, AVATAR_TONES.length)],
                      )}
                    >
                      {initialsOf(voter.full_name)}
                    </span>
                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                      <b
                        data-testid={`nonvoter-name-${index + 1}`}
                        title={voter.full_name}
                        className="truncate text-md leading-tight font-medium text-ink"
                      >
                        {voter.full_name}
                      </b>
                      <span className="truncate text-sm text-ink-soft">
                        {voter.group ? voter.group.name : t('turnout.nonvoters.noGroup')}
                      </span>
                    </span>
                  </li>
                ))}
              </ul>
            ) : none ? (
              <p data-testid="nonvoters-none" className="text-base text-ink-soft">
                {t('turnout.nonvoters.none')}
              </p>
            ) : filtering ? (
              <div
                data-testid="nonvoters-no-match"
                role="status"
                className="flex flex-col items-center gap-3 rounded-lg bg-surface-alt px-4 py-8 text-center"
              >
                <p className="text-base text-ink-soft">{t('turnout.nonvoters.noMatch')}</p>
                <button
                  type="button"
                  onClick={clearFilters}
                  className={cx(
                    'ui-control inline-flex min-h-11 items-center rounded-full px-4 text-base font-bold text-primary-hover hover:bg-primary-soft',
                    focusRing,
                  )}
                >
                  {t('turnout.nonvoters.reset')}
                </button>
              </div>
            ) : (
              <div
                data-testid="nonvoters-all-voted"
                role="status"
                className="flex flex-col items-center gap-2 rounded-lg bg-status-open-soft px-4 py-8 text-center text-status-open"
              >
                <span
                  aria-hidden="true"
                  className="float-art flex size-16 items-center justify-center rounded-full bg-surface"
                >
                  <Icon name="check" size={30} />
                </span>
                <p className="font-display text-lg font-extrabold">
                  {t('turnout.nonvoters.allVoted')}
                </p>
                <p className="text-base">{t('turnout.nonvoters.allVotedText')}</p>
              </div>
            )}

            {list.total > 0 ? (
              <nav
                aria-label={t('turnout.nonvoters.pager')}
                className="flex flex-col items-center justify-between gap-3 md:flex-row"
              >
                <p data-testid="nonvoters-range" className="text-base text-ink-soft">
                  {t('turnout.nonvoters.range', { from, to, total: list.total })}
                </p>
                {pages > 1 ? (
                  <div className="flex items-center gap-1.5">
                    <button
                      type="button"
                      data-testid="nonvoters-prev"
                      aria-label={t('turnout.nonvoters.prev')}
                      disabled={filters.page <= 1}
                      onClick={() => setFilters((now) => ({ ...now, page: now.page - 1 }))}
                      className={PAGE_BUTTON}
                    >
                      <Icon name="left" size={18} />
                    </button>
                    <button
                      type="button"
                      data-testid="nonvoters-next"
                      aria-label={t('turnout.nonvoters.next')}
                      disabled={filters.page >= pages}
                      onClick={() => setFilters((now) => ({ ...now, page: now.page + 1 }))}
                      className={PAGE_BUTTON}
                    >
                      <Icon name="right" size={18} />
                    </button>
                  </div>
                ) : null}
              </nav>
            ) : null}
          </Panel>
        </section>

        <aside
          aria-label={t('turnout.groups.title')}
          className="flex w-full flex-wrap items-start gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col 2xl:flex-nowrap 2xl:items-stretch"
        >
          <Panel
            tone="facts"
            icon="list"
            title={t('turnout.groups.title')}
            id="turnout-groups-title"
            testId="turnout-groups-card"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            {lines.length > 0 ? (
              <>
                <p className="text-sm text-ink-soft">{t('turnout.groups.hint')}</p>
                <ul className="flex flex-col gap-2.5">
                  {lines.map((line, index) => {
                    const name = line.name ?? t('turnout.groups.none');
                    const selected = filters.group === line.key;
                    const percent = percentText(Math.round(line.share * 1000) / 10, locale);

                    return (
                      <li key={line.key}>
                        <button
                          type="button"
                          data-testid={`turnout-group-${index + 1}`}
                          aria-pressed={selected}
                          aria-label={`${name} ${t('turnout.groups.line', { voted: line.voted, voters: line.voters })}. ${t('turnout.groups.filter', { name })}`}
                          onClick={() => chooseGroup(selected ? '' : line.key)}
                          className={cx(
                            'ui-control lift-sm flex w-full min-w-0 flex-col gap-2 rounded-lg border p-3.5 text-left',
                            selected
                              ? 'border-primary bg-primary-soft'
                              : 'border-line bg-surface hover:bg-canvas',
                            focusRing,
                          )}
                        >
                          <span className="flex min-w-0 items-baseline justify-between gap-3">
                            <b className="min-w-0 truncate text-md font-bold text-ink">{name}</b>
                            <span className="shrink-0 font-display text-xl font-extrabold text-ink">
                              {t('turnout.groups.percent', { percent })}
                            </span>
                          </span>
                          <GrowBar
                            value={line.share}
                            trackClassName="h-2.5"
                            className="bg-bar-open"
                          />
                          <span className="text-sm font-medium text-ink-soft">
                            {t('turnout.groups.line', { voted: line.voted, voters: line.voters })}
                          </span>
                        </button>
                      </li>
                    );
                  })}
                </ul>
              </>
            ) : (
              <p data-testid="turnout-groups-empty" className="text-base text-ink-soft">
                {t('turnout.groups.empty')}
              </p>
            )}
          </Panel>

          <Panel
            tone="calendar"
            icon="lock"
            title={t('turnout.end.title')}
            id="turnout-end-title"
            testId="turnout-end-card"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <p data-testid="turnout-end" className="font-display text-xl font-extrabold text-ink">
              {t('turnout.end.line', {
                day: longDay(end, locale, zone),
                time: clock(end, locale, zone),
              })}
            </p>
            <p className="flex gap-2.5 rounded-md bg-primary-soft px-3.5 py-3 text-base text-ink">
              <span aria-hidden="true" className="text-primary">
                <Icon name="lock" size={20} />
              </span>
              {t('turnout.secrecy')}
            </p>
          </Panel>
        </aside>
      </div>
    </div>
  );
}
