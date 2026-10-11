'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Input, Notice } from '@/components/ui';
import { ApiError, waitText } from '@/lib/api/errors';
import { openSession, VoteError, type VotePhase } from '@/lib/api/vote';
import { useI18n } from '@/lib/i18n/client';
import { Countdown } from './Countdown';
import { VoteCard } from './VoteCard';
import { useVote } from './VoteProvider';
import { VoteShell } from './VoteShell';
import { whenText } from './voteTime';

type Problem = { text: string; onField: boolean };

/** M1: the code, the countdown before the start, the closed notice after the end. */
export function CodeEntry() {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const { election, info, notice, setPhase, reloadInfo } = useVote();
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

  let main;

  if (info.phase === 'before') {
    main = (
      <VoteCard tone="getting-started" icon="clock" title={t('vote.before.heading')}>
        <p className="text-md text-ink-2">{t('vote.before.text')}</p>
        <Countdown target={info.starts_at} onDue={reloadInfo} />
        <p className="flex items-center gap-2 text-base font-medium text-ink-soft">
          <Icon name="calendar" size={18} />
          {t('vote.before.opensAt', { when: starts })}
        </p>
      </VoteCard>
    );
  } else if (info.phase === 'closed') {
    main = (
      <VoteCard
        tone="activity"
        icon="lock"
        title={t('vote.closed.heading')}
        data-testid="vote-closed"
      >
        <p className="text-md text-ink-2">{t('vote.closed.text')}</p>
        {info.results ? (
          <a
            href={info.results}
            className="ui-control inline-flex min-h-12 items-center justify-center rounded-full border border-primary bg-primary px-6 text-md font-semibold text-surface shadow-button hover:border-primary-hover hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
          >
            {t('vote.closed.results')}
          </a>
        ) : null}
      </VoteCard>
    );
  } else {
    main = (
      <VoteCard tone="getting-started" icon="lock" title={t('vote.code.heading')}>
        {notice ? (
          <Notice
            tone={notice === 'ended' ? 'teal' : 'warm'}
            role="status"
            data-testid="vote-code-notice"
          >
            {t(`vote.code.notice.${notice}`)}
          </Notice>
        ) : null}
        <p className="text-md text-ink-2">{t('vote.code.lede')}</p>
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-5">
          <Input
            data-testid="vote-code-input"
            label={t('vote.code.label')}
            help={t('vote.code.help')}
            value={code}
            onChange={(event) => setCode(event.target.value)}
            autoComplete="off"
            autoCapitalize="characters"
            autoCorrect="off"
            spellCheck={false}
            maxLength={32}
            error={problem?.onField ? problem.text : undefined}
            errorTestId="vote-code-error"
            className="font-mono tracking-widest uppercase"
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
            className="w-full"
          >
            {t('vote.code.submit')}
          </Button>
        </form>
      </VoteCard>
    );
  }

  return (
    <VoteShell hero={hero} live testId="vote-page">
      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
        <div className="min-w-0">{main}</div>
        <div className="flex min-w-0 flex-col gap-4">
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
                    <span className="font-semibold text-ink">
                      {t(`vote.code.step${step}Title`)}
                    </span>
                    <span className="text-base text-ink-soft">
                      {t(`vote.code.step${step}Text`)}
                    </span>
                  </span>
                </li>
              ))}
            </ol>
          </VoteCard>
        </div>
      </div>
    </VoteShell>
  );
}
