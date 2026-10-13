'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Notice } from '@/components/ui';
import { ApiError, waitText } from '@/lib/api/errors';
import { openSession, VoteError, type VotePhase } from '@/lib/api/vote';
import { useI18n } from '@/lib/i18n/client';
import { BeforeMain, BeforeRail } from './BeforeOpening';
import { CodeBoxes } from './CodeBoxes';
import { VoteCard } from './VoteCard';
import { useVote } from './VoteProvider';
import { VoteShell } from './VoteShell';
import { whenText } from './voteTime';

type Problem = { text: string; onField: boolean };

/** M1: the code, the countdown before the start, the closed notice after the end. */
export function CodeEntry() {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const { election, info, notice, setPhase } = useVote();
  const [code, setCode] = useState('');
  const [problem, setProblem] = useState<Problem | null>(null);
  const [busy, setBusy] = useState(false);
  const sending = useRef(false);
  const read = useRef(false);

  const submit = useCallback(
    async (raw: string) => {
      const value = raw.trim();

      if (value === '') {
        setProblem({ text: t('vote.error.empty'), onField: true });

        return;
      }

      if (sending.current) return;

      sending.current = true;
      setBusy(true);
      setProblem(null);

      try {
        await openSession(election, value);
        setCode('');
        router.push(`/vote/${election}/identity`);
      } catch (error) {
        const failure = error instanceof ApiError ? error : new ApiError(0, 'network');

        if (failure.code === 'invalid_code') {
          setProblem({ text: t('vote.error.invalid_code'), onField: true });
        } else if (failure.code === 'already_voted') {
          setProblem({ text: t('vote.error.already_voted'), onField: false });
        } else if (failure.code === 'election_not_open') {
          const phase: VotePhase | null = error instanceof VoteError ? error.phase : null;

          if (phase) setPhase(phase);

          setProblem({
            text: t(
              phase === 'before'
                ? 'vote.error.election_not_open_before'
                : phase === 'paused'
                  ? 'vote.error.election_not_open_paused'
                  : 'vote.error.election_not_open_closed',
            ),
            onField: false,
          });
        } else {
          setProblem({ text: waitText(failure, tIfAny), onField: false });
        }
      } finally {
        sending.current = false;
        setBusy(false);
      }
    },
    [election, router, setPhase, t, tIfAny],
  );

  // The personal link carries the code after "#": it never reaches a server on its own. It is read
  // once, taken out of the address bar at once (history, not just the display) and used from memory.
  useEffect(() => {
    if (read.current) return;
    read.current = true;

    const hash = window.location.hash;

    if (hash.length <= 1) return;

    let fromLink = hash.slice(1);

    try {
      fromLink = decodeURIComponent(fromLink);
    } catch {
      // Used as written.
    }

    window.history.replaceState(
      window.history.state,
      '',
      window.location.pathname + window.location.search,
    );

    // The link's code goes into the field once, at arrival: an effect, because only the browser sees the fragment.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setCode(fromLink);

    if (info.phase === 'open') void submit(fromLink);
  }, [info.phase, submit]);

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    void submit(code);
  }

  const ends = whenText(info.ends_at, locale, info.timezone, t);
  const starts = whenText(info.starts_at, locale, info.timezone, t);

  const hero = (
    <div className="flex flex-col gap-2">
      <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
        <Icon name="ballot" size={18} />
        {t('vote.code.kicker')}
      </p>
      <h1 data-testid="vote-election-title" className="text-3xl text-surface md:text-4xl">
        {info.title}
      </h1>
    </div>
  );

  const main = (
    <section
      aria-labelledby="vote-code-heading"
      className="code-card flex min-w-0 flex-col overflow-hidden rounded-lg bg-surface"
    >
      <header
        data-head="getting-started"
        className="dash-head relative flex items-center gap-4 overflow-hidden px-5 py-5 sm:px-8"
      >
        <span
          aria-hidden="true"
          className="code-key flex size-14 shrink-0 items-center justify-center rounded-full bg-glass-line sm:size-16"
        >
          <Icon name="key" size={32} />
        </span>
        <div className="flex min-w-0 flex-col">
          <h2 id="vote-code-heading" className="font-display text-2xl font-extrabold sm:text-3xl">
            {t('vote.code.heading')}
          </h2>
          <p className="text-base text-hero-ink-soft">{t('vote.code.stepHint')}</p>
        </div>
      </header>
      <div className="flex flex-col gap-6 px-4 py-6 sm:px-8 sm:py-8">
        {notice ? (
          <Notice
            tone={notice === 'ended' ? 'teal' : 'warm'}
            role="status"
            data-testid="vote-code-notice"
          >
            {t(`vote.code.notice.${notice}`)}
          </Notice>
        ) : null}
        <p className="text-center text-md text-ink-2">{t('vote.code.lede')}</p>
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
          <CodeBoxes
            testId="vote-code-input"
            label={t('vote.code.label')}
            help={t('vote.code.help')}
            value={code}
            onChange={setCode}
            error={problem?.onField ? problem.text : undefined}
            errorTestId="vote-code-error"
          />
          {problem && !problem.onField ? (
            <Notice tone="danger" role="alert" data-testid="vote-code-error">
              {problem.text}
            </Notice>
          ) : null}
          <Button
            type="submit"
            variant="primary"
            size="voter"
            shimmer
            loading={busy}
            data-testid="vote-code-submit"
            className="mx-auto min-h-14 w-full max-w-xl text-lg"
          >
            <span className="code-go inline-flex items-center gap-2">
              {t('vote.code.submit')}
              <Icon name="open" size={22} />
            </span>
          </Button>
        </form>
      </div>
    </section>
  );

  const rail = (
    <>
      <VoteCard tone="latest" icon="calendar" title={t('vote.code.periodTitle')}>
        <div className="grid grid-cols-2 gap-3">
          <div className="flex min-w-0 flex-col gap-1 rounded-md bg-primary-soft p-3">
            <span className="text-sm font-medium text-ink-2">{t('vote.period.start')}</span>
            <span className="text-base font-bold text-ink">{starts}</span>
          </div>
          <div className="flex min-w-0 flex-col gap-1 rounded-md bg-warm-softer p-3">
            <span className="text-sm font-medium text-ink-2">{t('vote.period.end')}</span>
            <span className="text-base font-bold text-ink">{ends}</span>
          </div>
        </div>
      </VoteCard>
      <VoteCard tone="figures" icon="log" title={t('vote.code.secretTitle')}>
        <p className="text-md text-ink-2">{t('vote.code.secretText')}</p>
      </VoteCard>
      <VoteCard tone="activity" icon="list" title={t('vote.code.howTitle')}>
        <ol className="flex flex-col gap-3">
          {([1, 2, 3] as const).map((step) => (
            <li key={step} className="flex items-start gap-3">
              <span
                aria-hidden="true"
                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft font-display text-base font-extrabold text-primary-hover"
              >
                {step}
              </span>
              <span className="flex min-w-0 flex-col">
                <span className="font-semibold text-ink">{t(`vote.code.step${step}Title`)}</span>
                <span className="text-base text-ink-soft">{t(`vote.code.step${step}Text`)}</span>
              </span>
            </li>
          ))}
        </ol>
        <Link
          href="/guide/voter"
          data-testid="vote-guide-link"
          className="mt-3 inline-flex min-h-11 items-center font-semibold text-primary underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary"
        >
          {t('vote.code.guide')}
        </Link>
      </VoteCard>
    </>
  );

  // A paused election: its state alone and wide, what it means in a row beneath.
  if (info.phase === 'paused') {
    return (
      <VoteShell hero={hero} testId="vote-page">
        <div className="flex flex-col gap-4">
          <section
            data-testid="vote-paused"
            aria-labelledby="vote-paused-heading"
            className="id-card flex min-w-0 flex-col overflow-hidden rounded-lg border-2 border-warm bg-surface shadow-3"
          >
            <div className="flex flex-col items-center gap-5 bg-warm-softer px-5 py-6 text-center sm:flex-row sm:px-8 sm:text-left">
              <span
                aria-hidden="true"
                className="id-avatar relative flex size-24 shrink-0 items-center justify-center rounded-full bg-warm text-surface sm:size-28"
              >
                <Icon name="warn" size={44} />
              </span>
              <div className="flex min-w-0 flex-1 flex-col items-center gap-2 sm:items-start">
                <h2
                  id="vote-paused-heading"
                  className="font-display text-3xl font-extrabold text-warm-ink sm:text-4xl"
                >
                  {t('vote.paused.heading')}
                </h2>
                <ul className="flex flex-wrap justify-center gap-2 sm:justify-start">
                  {(
                    [
                      ['check', t('vote.paused.chipSafe')],
                      ['clock', t('vote.paused.chipEnd')],
                    ] as const
                  ).map(([icon, text]) => (
                    <li
                      key={icon}
                      className="flex items-center gap-2 rounded-full border border-warm bg-surface px-3 py-1 text-base font-medium text-warm-ink"
                    >
                      <Icon name={icon} size={16} />
                      {text}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
            <p className="p-5 text-md text-ink-2 sm:p-8">{t('vote.paused.text')}</p>
          </section>
          <div className="grid items-stretch gap-4 lg:grid-cols-3">
            <VoteCard tone="latest" icon="calendar" title={t('vote.code.periodTitle')}>
              <div className="grid grid-cols-2 gap-3">
                <div className="flex min-w-0 flex-col gap-1 rounded-md bg-primary-soft p-3">
                  <span className="text-sm font-medium text-ink-2">{t('vote.period.start')}</span>
                  <span className="text-base font-bold text-ink">{starts}</span>
                </div>
                <div className="flex min-w-0 flex-col gap-1 rounded-md bg-warm-softer p-3">
                  <span className="text-sm font-medium text-ink-2">{t('vote.period.end')}</span>
                  <span className="text-base font-bold text-ink">{ends}</span>
                </div>
              </div>
            </VoteCard>
            <VoteCard tone="figures" icon="log" title={t('vote.code.secretTitle')}>
              <p className="text-md text-ink-2">{t('vote.code.secretText')}</p>
            </VoteCard>
            <VoteCard tone="activity" icon="clock" title={t('vote.paused.whatTitle')}>
              <p className="text-md text-ink-2">{t('vote.paused.hint')}</p>
            </VoteCard>
          </div>
        </div>
      </VoteShell>
    );
  }

  // A scheduled election: the wait alone and wide, the notes of the voting day in a row beneath.
  if (info.phase === 'before') {
    return (
      <VoteShell hero={hero} testId="vote-page">
        <div className="flex flex-col gap-4">
          <BeforeMain />
          <BeforeRail />
        </div>
      </VoteShell>
    );
  }

  // A closed election: its state alone and wide, what it means in a row beneath.
  if (info.phase === 'closed') {
    const published = Boolean(info.results);

    return (
      <VoteShell hero={hero} testId="vote-page">
        <div className="flex flex-col gap-4">
          <section
            data-testid="vote-closed"
            aria-labelledby="vote-closed-heading"
            className="id-card flex min-w-0 flex-col overflow-hidden rounded-lg border-2 border-primary bg-surface shadow-3"
          >
            <div className="id-band flex flex-col items-center gap-5 px-5 py-6 text-center sm:flex-row sm:px-8 sm:text-left">
              <span
                aria-hidden="true"
                className="id-avatar cand-avatar relative flex size-24 shrink-0 items-center justify-center rounded-full text-surface sm:size-28"
                data-sex="male"
              >
                <Icon name="lock" size={44} />
              </span>
              <div className="flex min-w-0 flex-1 flex-col items-center gap-2 sm:items-start">
                <h2
                  id="vote-closed-heading"
                  className="font-display text-3xl font-extrabold text-ink sm:text-4xl"
                >
                  {t('vote.closed.heading')}
                </h2>
                <p className="text-md text-ink-2">{t('vote.closed.text')}</p>
                <ul className="flex flex-wrap justify-center gap-2 sm:justify-start">
                  {(
                    [
                      ['calendar', t('vote.closed.at', { when: ends })],
                      ['shield', t('vote.closed.secret')],
                    ] as const
                  ).map(([icon, text]) => (
                    <li
                      key={icon}
                      className="flex items-center gap-2 rounded-full border border-primary-line bg-surface px-3 py-1 text-base font-medium text-ink-2"
                    >
                      <Icon name={icon} size={16} />
                      {text}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
            <div className="flex flex-col items-center gap-4 p-5 text-center sm:flex-row sm:p-8 sm:text-left">
              <span
                aria-hidden="true"
                className={
                  published
                    ? 'flex size-12 shrink-0 items-center justify-center rounded-full bg-status-open-soft text-status-open'
                    : 'flex size-12 shrink-0 items-center justify-center rounded-full bg-warm-softer text-warm-ink'
                }
              >
                <Icon name={published ? 'chart' : 'clock'} size={24} />
              </span>
              <div className="flex min-w-0 flex-1 flex-col">
                <p className="font-display text-xl font-extrabold text-ink">
                  {t(published ? 'vote.closed.publishedTitle' : 'vote.closed.pendingTitle')}
                </p>
                <p className="text-base text-ink-soft">
                  {t(published ? 'vote.closed.publishedText' : 'vote.closed.pendingText')}
                </p>
              </div>
              {info.results ? (
                <a
                  href={info.results}
                  className="ui-control inline-flex min-h-14 shrink-0 items-center justify-center gap-2 rounded-full border border-primary bg-primary px-8 text-md font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
                >
                  <Icon name="chart" size={20} />
                  {t('vote.closed.results')}
                </a>
              ) : null}
            </div>
          </section>
          <div className="grid items-stretch gap-4 lg:grid-cols-3">
            <VoteCard tone="latest" icon="calendar" title={t('vote.code.periodTitle')}>
              <div className="grid grid-cols-2 gap-3">
                <div className="flex min-w-0 flex-col gap-1 rounded-md bg-primary-soft p-3">
                  <span className="text-sm font-medium text-ink-2">{t('vote.period.start')}</span>
                  <span className="text-base font-bold text-ink">{starts}</span>
                </div>
                <div className="flex min-w-0 flex-col gap-1 rounded-md bg-warm-softer p-3">
                  <span className="text-sm font-medium text-ink-2">{t('vote.period.end')}</span>
                  <span className="text-base font-bold text-ink">{ends}</span>
                </div>
              </div>
            </VoteCard>
            <VoteCard tone="figures" icon="log" title={t('vote.closed.secretTitle')}>
              <p className="text-md text-ink-2">{t('vote.code.secretText')}</p>
            </VoteCard>
            <VoteCard tone="activity" icon="phone" title={t('vote.closed.helpTitle')}>
              <p className="text-md text-ink-2">{t('vote.closed.helpText')}</p>
            </VoteCard>
          </div>
        </div>
      </VoteShell>
    );
  }

  // The open vote gives the page to the code: alone and wide, the three notes in a row beneath it.
  return (
    <VoteShell hero={hero} live testId="vote-page">
      <div className="flex flex-col gap-5">
        {main}
        <div className="grid items-stretch gap-4 lg:grid-cols-3">{rail}</div>
      </div>
    </VoteShell>
  );
}
