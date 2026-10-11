'use client';

import { Icon, type IconName } from '@/components/admin/Icon';
import { Float, LiveDot } from '@/components/motion';
import { useI18n } from '@/lib/i18n/client';
import { Countdown } from './Countdown';
import { VoteCard } from './VoteCard';
import { useVote } from './VoteProvider';
import { whenText } from './voteTime';

/** The medallion: a clock on the hero gradient, rings going out, a calendar and a lock hanging on it. */
function Medallion() {
  return (
    <div aria-hidden="true" className="relative mx-auto size-32 shrink-0 sm:mx-0 sm:size-36">
      <span className="absolute inset-0 animate-ping rounded-full border-2 border-primary-line motion-reduce:animate-none" />
      <span className="absolute -inset-2 rounded-full border border-primary-line bg-primary-soft" />
      <span className="float-art bg-hero absolute inset-2 flex items-center justify-center rounded-full text-surface shadow-3">
        <Icon name="clock" size={64} />
      </span>
      <Float tilt="b" className="absolute -right-1 -bottom-1">
        <span className="flex size-12 items-center justify-center rounded-full border-2 border-surface bg-accent text-deep shadow-2">
          <Icon name="calendar" size={24} />
        </span>
      </Float>
      <Float tilt="a" className="absolute -top-1 -left-1">
        <span className="flex size-10 items-center justify-center rounded-full border-2 border-surface bg-teal text-surface shadow-2">
          <Icon name="lock" size={20} />
        </span>
      </Float>
    </div>
  );
}

/** The two moments of the vote, in the election's own time zone. */
function Moment({
  icon,
  label,
  value,
  tone,
}: {
  icon: IconName;
  label: string;
  value: string;
  tone: 'open' | 'close';
}) {
  return (
    <div
      className={
        tone === 'open'
          ? 'flex min-w-0 items-start gap-3 rounded-md bg-primary-soft p-4'
          : 'flex min-w-0 items-start gap-3 rounded-md bg-warm-softer p-4'
      }
    >
      <span
        aria-hidden="true"
        className={
          tone === 'open'
            ? 'flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-surface'
            : 'flex size-10 shrink-0 items-center justify-center rounded-full bg-warm text-surface'
        }
      >
        <Icon name={icon} size={20} />
      </span>
      <span className="flex min-w-0 flex-col gap-0.5">
        <span className="text-sm font-medium text-ink-2">{label}</span>
        <span className="text-md font-bold text-ink">{value}</span>
      </span>
    </div>
  );
}

/** Main column of the screen shown while the election is scheduled and not open yet. */
export function BeforeMain() {
  const { t, locale } = useI18n();
  const { info, reloadInfo } = useVote();
  const starts = whenText(info.starts_at, locale, info.timezone, t);
  const ends = whenText(info.ends_at, locale, info.timezone, t);

  return (
    <div className="flex min-w-0 flex-col gap-4">
      <VoteCard tone="getting-started" icon="clock" title={t('vote.before.status')}>
        <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-center">
          <Medallion />
          <div className="flex min-w-0 flex-col gap-2 text-center sm:text-left">
            <p className="flex items-center justify-center gap-2 text-sm font-semibold tracking-wide text-status-scheduled uppercase sm:justify-start">
              <LiveDot className="text-status-scheduled" />
              {t('vote.before.countdownTitle')}
            </p>
            <h2 className="text-2xl text-ink md:text-3xl">{t('vote.before.heading')}</h2>
            <p className="text-md text-ink-2">{t('vote.before.text')}</p>
          </div>
        </div>
        <Countdown target={info.starts_at} onDue={reloadInfo} />
      </VoteCard>
      <VoteCard tone="latest" icon="calendar" title={t('vote.before.whenTitle')}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Moment icon="rocket" label={t('vote.period.start')} value={starts} tone="open" />
          <Moment icon="flag" label={t('vote.period.end')} value={ends} tone="close" />
        </div>
        <p className="flex items-center gap-2 text-base text-ink-soft">
          <Icon name="globe" size={18} />
          {t('vote.before.zone', { zone: info.timezone })}
        </p>
      </VoteCard>
    </div>
  );
}

/** Right rail: how voting day goes, the secrecy, the code to keep ready. */
export function BeforeRail() {
  const { t } = useI18n();
  const steps: { n: 1 | 2 | 3 | 4; icon: IconName }[] = [
    { n: 1, icon: 'qr' },
    { n: 2, icon: 'lock' },
    { n: 3, icon: 'ballot' },
    { n: 4, icon: 'check' },
  ];

  return (
    <div className="flex min-w-0 flex-col gap-4">
      <VoteCard tone="activity" icon="list" title={t('vote.before.nextTitle')}>
        <ol className="flex flex-col gap-3">
          {steps.map((step) => (
            <li key={step.n} className="flex items-start gap-3">
              <span
                aria-hidden="true"
                className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
              >
                <Icon name={step.icon} size={20} />
              </span>
              <span className="flex min-w-0 flex-col">
                <span className="font-semibold text-ink">
                  {`${step.n}. ${t(`vote.before.step${step.n}Title`)}`}
                </span>
                <span className="text-base text-ink-soft">
                  {t(`vote.before.step${step.n}Text`)}
                </span>
              </span>
            </li>
          ))}
        </ol>
      </VoteCard>
      <VoteCard tone="figures" icon="eye" title={t('vote.before.secretTitle')}>
        <p className="text-md text-ink-2">{t('vote.before.secretText')}</p>
      </VoteCard>
      <section className="flex min-w-0 items-start gap-3 rounded-lg border border-warm bg-warm-softer p-4 shadow-1">
        <span
          aria-hidden="true"
          className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warm text-surface"
        >
          <Icon name="mail" size={20} />
        </span>
        <div className="flex min-w-0 flex-col gap-1">
          <h2 className="font-display text-lg font-extrabold text-warm-ink">
            {t('vote.before.readyTitle')}
          </h2>
          <p className="text-base text-warm-ink">{t('vote.before.readyText')}</p>
        </div>
      </section>
    </div>
  );
}
