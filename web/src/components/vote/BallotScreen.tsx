'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Slide } from '@/components/motion';
import { Button, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { errorText } from '@/lib/api/errors';
import type { VoteBallot, VoteCandidate } from '@/lib/api/vote';
import { useI18n } from '@/lib/i18n/client';
import { CandidateTile } from './CandidateTile';
import { VoteCard } from './VoteCard';
import { useVote, useVoterBallots } from './VoteProvider';
import { VoteShell } from './VoteShell';

const fullName = (candidate: VoteCandidate) => `${candidate.first_name} ${candidate.last_name}`;

/** M3: one ballot per screen, the photos first. `n` is the position among the voter's ballots, from 1. */
export function BallotScreen({ n: routeN }: { n: number }) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const { election, choices, choose } = useVote();
  const { ballots, failure, retry } = useVoterBallots();
  const title = useRef<HTMLHeadingElement>(null);

  // The ballot shown follows the press at once; the address follows when the route has loaded. A
  // different address from outside (the browser's back button) takes over again.
  const [seen, setSeen] = useState(routeN);
  const [n, setPosition] = useState(routeN);

  if (routeN !== seen) {
    setSeen(routeN);
    setPosition(routeN);
  }

  const total = ballots?.ballots.length ?? 0;
  const outside = ballots !== null && (n < 1 || n > total || !Number.isInteger(n));

  useEffect(() => {
    if (outside)
      router.replace(total > 0 ? `/vote/${election}/ballot/1` : `/vote/${election}/identity`);
  }, [outside, total, election, router]);

  // A new ballot: the screen reader starts at its title.
  useEffect(() => {
    title.current?.focus({ preventScroll: true });
  }, [n, ballots]);

  if (!ballots || outside) {
    return (
      <VoteShell
        testId="vote-ballot-loading"
        hero={<h1 className="text-3xl text-surface md:text-4xl">{t('vote.ballot.loading')}</h1>}
      >
        {failure ? (
          <Notice tone="danger" role="alert">
            <p>{errorText(failure, tIfAny)}</p>
            <Button variant="secondary" size="voter" className="mt-3" onClick={retry}>
              {t('vote.code.submit')}
            </Button>
          </Notice>
        ) : (
          <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4" aria-hidden="true">
            {[1, 2, 3, 4].map((tile) => (
              <div key={tile} className="shimmer aspect-[4/5] rounded-lg" />
            ))}
          </div>
        )}
      </VoteShell>
    );
  }

  const ballot: VoteBallot = ballots.ballots[n - 1]!;
  const chosen = choices[ballot.id];
  const blank = chosen !== undefined && chosen.length === 0;
  const picked = chosen ?? [];
  const full = ballot.seats > 1 && picked.length >= ballot.seats;
  const ready = chosen !== undefined;
  const last = n === total;
  const base = `/vote/${election}`;

  /** To a ballot, or to the identity (0) or the review (after the last). */
  function go(to: number) {
    if (to < 1) return router.push(`${base}/identity`);
    if (to > total) return router.push(`${base}/review`);

    setPosition(to);
    router.push(`${base}/ballot/${to}`);
  }

  function toggle(candidate: VoteCandidate) {
    if (picked.includes(candidate.id)) {
      const rest = picked.filter((id) => id !== candidate.id);

      choose(ballot.id, rest.length > 0 ? rest : null);

      return;
    }

    if (ballot.seats === 1) return choose(ballot.id, [candidate.id]);
    if (picked.length < ballot.seats) choose(ballot.id, [...picked, candidate.id]);
  }

  const names = ballot.candidates.filter((c) => picked.includes(c.id)).map(fullName);
  const reminder = blank
    ? t('vote.ballot.reminderBlank')
    : names.length === 1
      ? t('vote.ballot.reminderOne', { name: names[0]! })
      : names.length > 1
        ? t('vote.ballot.reminderMany', { count: names.length })
        : t('vote.ballot.reminderNone');

  const hero = (
    <div className="flex flex-col gap-3">
      <div data-testid="vote-ballot-progress" className="flex flex-col gap-2">
        <p className="flex flex-wrap items-center gap-x-3 text-sm font-semibold text-hero-ink-soft">
          <span>{t('vote.ballot.progress', { n, total })}</span>
          <span className="inline-flex items-center gap-1">
            <Icon name="user" size={14} />
            {ballots.voter.full_name}
            {ballots.voter.group ? ` · ${ballots.voter.group}` : ''}
          </span>
        </p>
        <div
          aria-hidden="true"
          className="grid gap-1.5"
          style={{ gridTemplateColumns: `repeat(${total}, minmax(0, 1fr))` }}
        >
          {ballots.ballots.map((item, index) => (
            <span
              key={item.id}
              className={cx('h-1.5 rounded-full', index < n ? 'bg-accent-light' : 'bg-glass-line')}
            />
          ))}
        </div>
      </div>
      <h1
        ref={title}
        tabIndex={-1}
        data-testid="vote-ballot-title"
        className="text-3xl text-surface outline-none md:text-4xl"
      >
        {ballot.title}
      </h1>
    </div>
  );

  const bar = (
    <div className="flex flex-col gap-2 md:flex-row md:items-center md:gap-4">
      <p aria-live="polite" className="min-w-0 flex-1 truncate text-base font-medium text-ink-2">
        {reminder}
      </p>
      <div className="flex gap-3">
        <Button
          variant="secondary"
          size="voter"
          data-testid="vote-ballot-back"
          onClick={() => go(n - 1)}
        >
          {t('vote.ballot.back')}
        </Button>
        <Button
          variant="primary"
          size="voter"
          shimmer={ready}
          disabled={!ready}
          data-testid="vote-ballot-next"
          className="min-w-0 flex-1 md:flex-none"
          onClick={() => go(n + 1)}
        >
          {last ? t('vote.ballot.review') : t('vote.ballot.next')}
        </Button>
      </div>
    </div>
  );

  return (
    <VoteShell hero={hero} bar={bar} testId="vote-ballot">
      {/* The slide-in starts 24 px to the right: clipped, or a phone widens the page for good. */}
      <div className="-mx-2 overflow-x-clip px-2">
        <Slide key={n} className="flex flex-col gap-4">
          <VoteCard
            tone="latest"
            icon="ballot"
            title={t('vote.ballot.cardTitle')}
            bodyClassName="gap-3"
          >
            <p data-testid="vote-ballot-hint" className="text-center text-md font-medium text-ink">
              {ballot.seats === 1
                ? t('vote.ballot.seatsOne')
                : t('vote.ballot.seatsMany', { seats: ballot.seats })}
              {ballot.seats > 1 ? (
                <span className="ml-2 text-ink-soft">
                  {t('vote.ballot.chosenCount', { count: picked.length, seats: ballot.seats })}
                </span>
              ) : null}
            </p>
            {full ? (
              <p className="text-center text-base font-medium text-warm-ink">
                {t('vote.ballot.full')}
              </p>
            ) : null}
            {ballot.description ? (
              <p className="text-center text-base text-ink-soft">{ballot.description}</p>
            ) : null}

            <div
              role="group"
              aria-label={t('vote.ballot.legend', { title: ballot.title })}
              className="flex flex-wrap justify-center gap-3 md:gap-5"
            >
              {ballot.candidates.map((candidate, index) => (
                <CandidateTile
                  key={candidate.id}
                  candidate={candidate}
                  index={index + 1}
                  selected={picked.includes(candidate.id)}
                  locked={full && !picked.includes(candidate.id)}
                  onToggle={() => toggle(candidate)}
                />
              ))}
            </div>

            {ballot.allow_blank ? (
              <button
                type="button"
                data-testid="vote-blank"
                aria-pressed={blank}
                onClick={() => choose(ballot.id, blank ? null : [])}
                className="select-ring ui-control lift-sm flex min-h-16 w-full items-center gap-3 rounded-lg border-dashed bg-surface px-4 py-3 text-left focus-visible:outline-2 focus-visible:outline-primary"
              >
                <span
                  aria-hidden="true"
                  className="flex size-10 shrink-0 items-center justify-center rounded-full bg-line-soft text-ink-2"
                >
                  <Icon name="blank" size={20} />
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="text-md font-bold text-ink">{t('vote.ballot.blank')}</span>
                  <span className="text-sm text-ink-soft">{t('vote.ballot.blankHint')}</span>
                </span>
                {blank ? (
                  <span
                    aria-hidden="true"
                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-deep"
                  >
                    <Icon name="check" size={18} />
                  </span>
                ) : null}
              </button>
            ) : null}
          </VoteCard>
        </Slide>
      </div>
    </VoteShell>
  );
}
