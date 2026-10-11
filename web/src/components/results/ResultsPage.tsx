'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing, linkPrimary } from '@/components/admin/classes';
import { Panel } from '@/components/elections/Panel';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { Reveal } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import { fetchResults } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import type { ResultBallot } from '@/lib/api/results';
import type { Turnout } from '@/lib/api/turnout';
import { clock, longDay, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { percentText } from '@/components/turnout/turnoutModel';
import { BallotResultCard } from './BallotResultCard';
import { SecondRoundDialog } from './SecondRoundDialog';
import { tiedNames } from './resultsModel';

const BADGE_TEXT: Record<ElectionStatus, string> = {
  draft: 'text-status-draft',
  scheduled: 'text-status-scheduled',
  open: 'text-status-open',
  closed: 'text-ink-soft',
  published: 'text-status-published',
  archived: 'text-ink-soft',
};

const CHIP =
  'inline-flex min-h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass py-1 pr-3.5 pl-2.5 text-base font-medium';

const BACK =
  'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink ' +
  focusRing;

/**
 * Screen A13: the results of one closed election, a card per ballot. `results` is null when the
 * API said the results are not available (the election is not closed): the page then shows a
 * calm explanation and never any count.
 */
export function ResultsPage({
  election,
  results,
  turnout,
}: {
  election: Election;
  results: ResultBallot[] | null;
  turnout: Turnout | null;
}) {
  const { t, locale } = useI18n();
  const [ballots, setBallots] = useState<ResultBallot[] | null>(results);
  const [asking, setAsking] = useState<ResultBallot | null>(null);

  if (ballots === null) {
    return (
      <div className="flex flex-col gap-4.5">
        <Link href={`/admin/elections/${election.id}`} className={BACK}>
          <Icon name="left" size={18} />
          {t('results.back')}
        </Link>
        <section
          data-testid="results-unavailable"
          aria-labelledby="results-unavailable-title"
          className="flex flex-col items-center gap-3 rounded-2xl border border-line bg-surface px-5 py-12 text-center shadow-1"
        >
          <span
            aria-hidden="true"
            className="float-art flex size-16 items-center justify-center rounded-full bg-primary text-surface shadow-button"
          >
            <Icon name="lock" size={30} />
          </span>
          <h2
            id="results-unavailable-title"
            className="font-display text-xl font-extrabold text-ink"
          >
            {t('results.unavailable.title')}
          </h2>
          <p className="max-w-lg text-base text-ink-soft">
            {t(`results.unavailable.text.${election.status === 'open' ? 'open' : 'other'}`)}
          </p>
          <div className="mt-1 flex flex-wrap justify-center gap-3">
            <Link href={`/admin/elections/${election.id}`} className={linkPrimary}>
              {t('results.unavailable.election')}
            </Link>
            {election.status === 'open' ? (
              <Link
                href={`/admin/elections/${election.id}/turnout`}
                className={cx(
                  'ui-control inline-flex min-h-11 items-center gap-2 rounded-full border border-line-strong bg-surface px-5 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft',
                  focusRing,
                )}
              >
                <Icon name="chart" size={18} />
                {t('results.unavailable.turnout')}
              </Link>
            ) : null}
          </div>
        </section>
      </div>
    );
  }

  const list: ResultBallot[] = ballots;
  const ties = list.filter((ballot) => ballot.tie !== null).length;
  const end = new Date(election.ends_at);
  const zone = election.timezone;

  /** The API is read again: a second round created elsewhere shows as created here. */
  async function refresh() {
    setAsking(null);

    try {
      setBallots((await fetchResults(election.id)).ballots);
    } catch {
      // The page keeps what it shows; the dialog is closed.
    }
  }

  function created(ballot: string, newElection: string) {
    setBallots((now) =>
      (now ?? []).map((item) =>
        item.id === ballot ? { ...item, second_round: newElection } : item,
      ),
    );
    setAsking(null);
  }

  return (
    <div data-testid="results-page" className="flex flex-col gap-4.5">
      <Link href={`/admin/elections/${election.id}`} data-testid="results-back" className={BACK}>
        <Icon name="left" size={18} />
        {t('results.back')}
      </Link>

      <section
        data-testid="results-hero"
        data-status={election.status}
        aria-labelledby="results-title"
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
            <Icon name={STATUS_ICONS[election.status]} size={16} />
            <span data-status={election.status}>{t(`elections.status.${election.status}`)}</span>
          </span>
          <h2
            id="results-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('results.title')}
          </h2>
          <p className="line-clamp-2 text-md break-words text-surface">{election.title}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            {turnout ? (
              <span data-testid="results-chip-turnout" className={CHIP}>
                <Icon name="people" size={18} />
                {t(`results.hero.turnout.${pluralForm(locale, turnout.eligible)}`, {
                  voted: turnout.voted,
                  eligible: turnout.eligible,
                  percent: percentText(turnout.percentage, locale),
                })}
              </span>
            ) : null}
            <span className={CHIP}>
              <Icon name="ballot" size={18} />
              {t(`results.hero.ballots.${pluralForm(locale, list.length)}`, {
                count: list.length,
              })}
            </span>
            {ties > 0 ? (
              <span className={CHIP}>
                <Icon name="warn" size={18} />
                {t(`results.hero.ties.${pluralForm(locale, ties)}`, { count: ties })}
              </span>
            ) : null}
          </div>
        </div>
      </section>

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <section
          data-testid="results-zone"
          aria-label={t('results.zone')}
          className="min-w-0 flex-1 rounded-2xl border border-line bg-surface p-2.5 shadow-1 md:p-3 2xl:p-5"
        >
          {list.length > 0 ? (
            <Reveal
              stagger
              className="grid grid-cols-1 items-start gap-4.5 min-[1100px]:grid-cols-2 2xl:grid-cols-1 min-[2100px]:grid-cols-2"
            >
              {list.map((ballot, index) => (
                <BallotResultCard
                  key={ballot.id}
                  n={index + 1}
                  ballot={ballot}
                  onSecondRound={() => setAsking(ballot)}
                />
              ))}
            </Reveal>
          ) : (
            <p
              data-testid="results-empty"
              className="px-3 py-10 text-center text-base text-ink-soft"
            >
              {t('results.empty')}
            </p>
          )}
        </section>

        <aside
          aria-label={t('results.rail.label')}
          className="flex w-full flex-wrap items-start gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col 2xl:flex-nowrap 2xl:items-stretch"
        >
          <Panel
            tone="calendar"
            icon="flag"
            title={t('results.rail.election')}
            id="results-election-title"
            testId="results-election-card"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <p className="font-display text-lg font-extrabold break-words text-ink">
              {election.title}
            </p>
            <p className="text-base text-ink-soft">
              {t('results.rail.closed', {
                day: longDay(end, locale, zone),
                time: clock(end, locale, zone),
              })}
            </p>
            <Link
              href={`/admin/elections/${election.id}/turnout`}
              className={cx(
                'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-base font-bold text-primary-hover hover:underline',
                focusRing,
              )}
            >
              <Icon name="chart" size={18} />
              {t('results.rail.turnout')}
            </Link>
          </Panel>

          <Panel
            tone="steps"
            icon="eye"
            title={t('results.rail.legend')}
            id="results-legend-title"
            testId="results-legend-card"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <ul className="flex flex-col gap-2.5 text-base text-ink">
              {(
                [
                  ['check', 'winner'],
                  ['warn', 'tie'],
                  ['blank', 'blank'],
                  ['list', 'percent'],
                ] as const
              ).map(([icon, key]) => (
                <li key={key} className="flex items-start gap-2.5">
                  <span aria-hidden="true" className="mt-0.5 shrink-0 text-primary">
                    <Icon name={icon} size={18} />
                  </span>
                  {t(`results.rail.legendItems.${key}`)}
                </li>
              ))}
            </ul>
            <p className="flex gap-2.5 rounded-md bg-primary-soft px-3.5 py-3 text-base text-ink">
              <span aria-hidden="true" className="text-primary">
                <Icon name="lock" size={20} />
              </span>
              {t('results.rail.secrecy')}
            </p>
          </Panel>
        </aside>
      </div>

      {asking ? (
        <SecondRoundDialog
          election={election.id}
          ballot={asking.id}
          ballotTitle={asking.title}
          names={tiedNames(asking)}
          onCreated={(id) => created(asking.id, id)}
          onExists={() => void refresh()}
          onCancel={() => setAsking(null)}
        />
      ) : null}
    </div>
  );
}
