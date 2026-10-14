'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Notice } from '@/components/ui';
import { ApiError, waitText } from '@/lib/api/errors';
import { submitVote, VoteError } from '@/lib/api/vote';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { useVote, useVoterBallots } from './VoteProvider';
import { VoteShell } from './VoteShell';

/** M4: every choice in one place, with a way back to change one, then the single confirmation. */
export function Review() {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const { election, choices, setBallots, setPhase, setVotedAt, leave, forget } = useVote();
  const { ballots, failure, retry } = useVoterBallots();
  const [sending, setSending] = useState(false);
  const [problem, setProblem] = useState<{ text: string; restart: boolean } | null>(null);
  const once = useRef(false);

  const list = ballots?.ballots ?? [];
  const missing = ballots ? list.findIndex((ballot) => choices[ballot.id] === undefined) : -1;

  // A reload forgets the choices: back to the first ballot without one.
  useEffect(() => {
    if (ballots && missing >= 0 && !sending) {
      router.replace(`/vote/${election}/ballot/${missing + 1}`);
    }
  }, [ballots, missing, sending, election, router]);

  if (!ballots || missing >= 0) {
    return (
      <VoteShell
        testId="vote-review-loading"
        hero={<h1 className="text-3xl text-surface md:text-4xl">{t('vote.review.heading')}</h1>}
      >
        {failure ? (
          <Notice tone="danger" role="alert">
            <p>{waitText(failure, tIfAny)}</p>
            <Button variant="secondary" size="voter" className="mt-3" onClick={retry}>
              {t('vote.code.submit')}
            </Button>
          </Notice>
        ) : (
          <div className="shimmer h-40 rounded-lg" aria-hidden="true" />
        )}
      </VoteShell>
    );
  }

  async function confirm() {
    if (once.current || !ballots) return;

    once.current = true;
    setSending(true);
    setProblem(null);

    try {
      const answer = await submitVote(
        election,
        ballots.ballots.map((ballot) => ({
          ballot: ballot.id,
          candidates: choices[ballot.id] ?? [],
        })),
      );

      // 201 or 200 (an answer that had been lost): the vote is recorded either way.
      setVotedAt(answer.voted_at);
      // Replace: the back button must not return to a review of a vote that is already cast.
      router.replace(`/vote/${election}/done`);

      return;
    } catch (error) {
      const failed = error instanceof ApiError ? error : new ApiError(0, 'network');

      if (failed.code === 'voter_session_expired') return leave('expired');
      if (failed.code === 'already_voted') return leave('alreadyVoted');

      if (failed.code === 'election_not_open') {
        const phase = error instanceof VoteError ? error.phase : null;

        if (phase) setPhase(phase);

        return leave(null);
      }

      if (failed.code === 'choices_invalid' || failed.code === 'ballots_mismatch') {
        setProblem({ text: t(`vote.error.${failed.code}`), restart: true });
      } else {
        // A network failure or a limit: the same submission can be sent again, once.
        setProblem({ text: waitText(failed, tIfAny), restart: false });
      }
    }

    once.current = false;
    setSending(false);
  }

  function restart() {
    setBallots(null);
    forget();
    router.replace(`/vote/${election}/identity`);
  }

  const hero = (
    <div className="flex items-center gap-4">
      <span
        aria-hidden="true"
        className="code-key flex size-12 shrink-0 items-center justify-center rounded-full bg-glass-line sm:size-16"
      >
        <Icon name="shield" size={32} />
      </span>
      <div className="flex min-w-0 flex-col gap-1">
        <p className="text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
          {t('vote.review.kicker')}
        </p>
        <h1 className="text-3xl text-surface md:text-4xl">{t('vote.review.heading')}</h1>
        <p className="max-w-2xl text-base text-hero-ink-soft md:text-md">{t('vote.review.lede')}</p>
      </div>
    </div>
  );

  const bar = (
    <div className="flex flex-col gap-2 md:flex-row md:items-center md:gap-4">
      <p className="flex min-w-0 flex-1 items-center gap-2 truncate text-base font-medium text-ink-2">
        <Icon name="lock" size={16} />
        {t('vote.review.warning')}
      </p>
      <div className="flex gap-3">
        <Button
          variant="secondary"
          size="voter"
          disabled={sending}
          onClick={() => router.push(`/vote/${election}/ballot/${list.length}`)}
        >
          {t('vote.review.back')}
        </Button>
        <Button
          variant="primary"
          size="voter"
          shimmer
          loading={sending}
          data-testid="vote-confirm"
          className="min-w-0 flex-1 md:flex-none"
          onClick={() => void confirm()}
        >
          {t('vote.review.confirm')}
        </Button>
      </div>
    </div>
  );

  const confirmButton = (
    <Button
      variant="primary"
      size="voter"
      shimmer
      loading={sending}
      className="min-h-14 w-full text-lg"
      onClick={() => void confirm()}
    >
      <span className="inline-flex items-center gap-2">
        <Icon name="lock" size={20} />
        {t('vote.review.confirm')}
      </span>
    </Button>
  );

  return (
    <VoteShell hero={hero} bar={bar} testId="vote-review">
      <div className="flex flex-col gap-4">
        {problem ? (
          <Notice
            tone="danger"
            role="alert"
            data-testid="vote-review-error"
            actions={
              problem.restart ? (
                <Button variant="secondary" size="voter" onClick={restart}>
                  {t('vote.review.restart')}
                </Button>
              ) : undefined
            }
          >
            {problem.text}
          </Notice>
        ) : null}
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
          <section className="flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-3">
            <header
              data-head="getting-started"
              className="dash-head relative flex h-16 items-center gap-3 overflow-hidden px-5"
            >
              <span
                aria-hidden="true"
                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-glass-line"
              >
                <Icon name="ballot" size={22} />
              </span>
              <h2 className="font-display text-xl font-extrabold">{t('vote.review.slip')}</h2>
            </header>
            <ol className="flex flex-col">
              {list.map((ballot, index) => {
                const picked = choices[ballot.id] ?? [];
                const chosen = ballot.candidates.filter((candidate) =>
                  picked.includes(candidate.id),
                );

                return (
                  <li
                    key={ballot.id}
                    data-testid={`vote-review-ballot-${index + 1}`}
                    style={{ animationDelay: `${Math.min(index, 8) * 120}ms` }}
                    className="id-rise grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-3 border-b border-line-soft p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:px-5"
                  >
                    <span
                      aria-hidden="true"
                      className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary font-display text-base font-extrabold text-surface"
                    >
                      {index + 1}
                    </span>
                    <div className="flex min-w-0 flex-col gap-2">
                      <h3 className="text-sm font-semibold text-ink-soft">
                        {t('vote.review.ballotOf', { n: index + 1, title: ballot.title })}
                      </h3>
                      {chosen.length === 0 ? (
                        <p className="flex items-center gap-3">
                          <span className="relative flex size-14 shrink-0 items-center justify-center rounded-full bg-line-soft text-ink-2">
                            <Icon name="blank" size={26} />
                            <span className="id-check absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full border-2 border-surface bg-status-open text-surface">
                              <Icon name="check" size={12} />
                            </span>
                          </span>
                          <span className="flex min-w-0 flex-col">
                            <span className="font-display text-xl font-extrabold text-ink">
                              {t('vote.review.blank')}
                            </span>
                            <span className="text-sm text-ink-soft">
                              {t('vote.review.blankHint')}
                            </span>
                          </span>
                        </p>
                      ) : (
                        <ul className="flex flex-col gap-3">
                          {chosen.map((candidate) => (
                            <li key={candidate.id} className="flex items-center gap-3">
                              <span className="relative shrink-0">
                                {candidate.photo ? (
                                  // Decorative: the name is beside it.
                                  // eslint-disable-next-line @next/next/no-img-element
                                  <img
                                    src={candidate.photo}
                                    alt=""
                                    className="size-14 rounded-full border border-line object-cover"
                                  />
                                ) : (
                                  <span
                                    aria-hidden="true"
                                    data-sex={candidate.sex === 'female' ? 'female' : 'male'}
                                    className="cand-avatar flex size-14 items-center justify-center rounded-full"
                                  >
                                    <Icon name="user" size={26} />
                                  </span>
                                )}
                                <span
                                  aria-hidden="true"
                                  className="id-check absolute -right-1 -bottom-1 flex size-6 items-center justify-center rounded-full border-2 border-surface bg-status-open text-surface"
                                >
                                  <Icon name="check" size={12} />
                                </span>
                              </span>
                              <span className="flex min-w-0 flex-col">
                                <span className="font-display text-xl font-extrabold break-words text-ink">
                                  {candidate.first_name} {candidate.last_name}
                                </span>
                                <span className="flex items-center gap-2 text-sm text-ink-soft">
                                  <Icon name="party" size={14} />
                                  {candidate.party ? (
                                    <>
                                      <span
                                        aria-hidden="true"
                                        className="size-2.5 shrink-0 rounded-[3px]"
                                        style={{ backgroundColor: candidate.party.colour }}
                                      />
                                      {candidate.party.name}
                                    </>
                                  ) : (
                                    t('vote.ballot.independent')
                                  )}
                                </span>
                              </span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                    <Link
                      href={`/vote/${election}/ballot/${index + 1}`}
                      data-testid={`vote-review-edit-${index + 1}`}
                      className="ui-control col-start-2 inline-flex min-h-11 w-fit shrink-0 items-center gap-2 rounded-full border border-line-strong px-4 text-base font-semibold text-ink hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary sm:col-start-auto"
                    >
                      <Icon name="edit" size={16} />
                      {t('vote.review.edit')}
                    </Link>
                  </li>
                );
              })}
            </ol>
            <p className="flex items-center gap-3 bg-status-open-soft px-5 py-3 text-base font-semibold text-status-open">
              <Icon name="check" size={20} />
              {t(`vote.review.complete.${pluralForm(locale, list.length)}`, {
                count: list.length,
              })}
            </p>
            <div className="p-4 sm:p-5">{confirmButton}</div>
          </section>

          <section className="flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2">
            <header
              data-head="figures"
              className="dash-head relative flex h-16 items-center gap-3 overflow-hidden px-5"
            >
              <span
                aria-hidden="true"
                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-glass-line"
              >
                <Icon name="shield" size={22} />
              </span>
              <h2 className="font-display text-xl font-extrabold">{t('vote.review.railTitle')}</h2>
            </header>
            <ul className="flex flex-col gap-4 p-5">
              {(
                [
                  ['shield', t('vote.review.rail1')],
                  ['lock', t('vote.review.rail2')],
                  ['check', t('vote.review.rail3')],
                ] as const
              ).map(([icon, text]) => (
                <li key={icon} className="flex items-start gap-3 text-md text-ink-2">
                  <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
                  >
                    <Icon name={icon} size={18} />
                  </span>
                  {text}
                </li>
              ))}
            </ul>
          </section>
        </div>
      </div>
    </VoteShell>
  );
}
