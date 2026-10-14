'use client';

import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Check } from '@/components/motion';
import { Button } from '@/components/ui';
import { endSession } from '@/lib/api/vote';
import { useI18n } from '@/lib/i18n/client';
import { VoteCard } from './VoteCard';
import { useVote } from './VoteProvider';
import { VoteShell } from './VoteShell';
import { recordedText } from './voteTime';

/** Seconds before the screen goes back to code entry by itself (Jun, 2026-10-11). */
const RETURN_AFTER = 10;

/** M5: the vote is recorded; then back to code entry, by itself or at once. */
export function Done() {
  const { t, locale } = useI18n();
  const { election, info, votedAt, clearVoter, leave, signOut } = useVote();
  const [seconds, setSeconds] = useState(RETURN_AFTER);
  const [leaving, setLeaving] = useState(false);
  const left = useRef(false);

  // The choices are forgotten the moment the vote is recorded, and the session is ended: nothing of
  // this voter stays in the page or in the browser but the time to show.
  useEffect(() => {
    if (votedAt === null) {
      leave(null);

      return;
    }

    clearVoter();
    void endSession(election).catch(() => undefined);
    // Once, on arrival.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (votedAt === null) return;

    const timer = setInterval(() => setSeconds((value) => Math.max(0, value - 1)), 1000);

    return () => clearInterval(timer);
  }, [votedAt]);

  useEffect(() => {
    if (seconds > 0 || left.current || votedAt === null) return;

    left.current = true;
    void signOut('ended');
  }, [seconds, votedAt, signOut]);

  async function next() {
    if (left.current) return;

    left.current = true;
    setLeaving(true);
    await signOut('ended');
  }

  if (votedAt === null) return null;

  const hero = (
    <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:gap-5">
      <Check label={t('vote.done.heading')} className="size-16 shrink-0" />
      <h1 className="text-3xl text-surface md:text-4xl">{t('vote.done.heading')}</h1>
    </div>
  );

  return (
    <VoteShell hero={hero} live testId="vote-done">
      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <VoteCard tone="open-election" icon="check" title={t('vote.done.timeLabel')}>
          <p className="text-md text-ink-2">{t('vote.done.text')}</p>
          <p className="flex items-center gap-3 rounded-md bg-teal-soft p-4 text-teal-ink">
            <Icon name="clock" size={24} />
            <span data-testid="vote-done-time" className="font-display text-xl font-extrabold">
              {recordedText(votedAt, locale, info.timezone, t)}
            </span>
          </p>
          <div className="flex flex-col gap-3">
            <p
              data-testid="vote-done-countdown"
              role="timer"
              className="text-md font-medium text-ink"
            >
              {t('vote.done.countdown', { seconds })}
            </p>
            <div aria-hidden="true" className="h-2 overflow-hidden rounded-full bg-line-soft">
              <div
                className="h-full origin-left rounded-full bg-teal transition-transform duration-1000 ease-linear"
                style={{ transform: `scaleX(${seconds / RETURN_AFTER})` }}
              />
            </div>
          </div>
          <Button
            variant="primary"
            size="voter"
            shimmer
            loading={leaving}
            data-testid="vote-done-next"
            className="w-full sm:w-auto"
            onClick={() => void next()}
          >
            {t('vote.done.next')}
          </Button>
        </VoteCard>
        <VoteCard tone="figures" icon="log" title={t('vote.done.secretTitle')}>
          <p className="text-md text-ink-2">{t('vote.done.secretText')}</p>
          <p className="flex items-center gap-2 text-base text-ink-soft">
            <Icon name="lock" size={16} />
            {t('vote.done.leave')}
          </p>
        </VoteCard>
      </div>
    </VoteShell>
  );
}
