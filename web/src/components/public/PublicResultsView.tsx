import { Icon, type IconName } from '@/components/admin/Icon';
import { GrowBar, Reveal } from '@/components/motion';
import { ClosingBand } from '@/components/ui/ClosingBand';
import { Notice } from '@/components/ui/Notice';
import { cx } from '@/components/ui/cx';
import { percentText } from '@/components/turnout/turnoutModel';
import { VoteCard, type HeadTone } from '@/components/vote/VoteCard';
import type { PublicBallot, PublicResults } from '@/lib/api/public';
import { clock, joinList, longDay, pluralForm } from '@/lib/format/electionDates';
import type { Translate } from '@/lib/i18n/messages';
import type { Locale } from '@/lib/i18n/locale';
import {
  barOf,
  candidateName,
  isHighlighted,
  tiedCandidates,
  type ShareLinks,
} from '@/lib/public/share';
import { Avatar } from './Avatar';
import { CopyLinkButton } from './CopyLinkButton';
import { PublicShell } from './PublicShell';

const TONES: HeadTone[] = ['latest', 'activity', 'figures', 'getting-started'];

const CHIP =
  'inline-flex min-h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass py-1 pr-3.5 pl-2.5 text-base font-medium text-surface';

const ACTION =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line-strong bg-surface px-5 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

const SHARE_ICONS: Record<'whatsapp' | 'facebook' | 'x', IconName> = {
  whatsapp: 'phone',
  facebook: 'people',
  x: 'open',
};

/**
 * M-results: the public results of one published election, in the election's language. Server
 * rendered; the bars and the copy button are the only parts that run in the browser. Every
 * number comes from the API as it is: in the winners display there are none to show.
 */
export function PublicResultsView({
  results,
  t,
  locale,
  url,
  links,
  reportHref,
  imageHref,
  institutionHref,
}: {
  results: PublicResults;
  t: Translate;
  locale: Locale;
  /** The absolute address of this page. */
  url: string;
  links: ShareLinks;
  reportHref: string;
  imageHref: string;
  institutionHref: string;
}) {
  const full = results.display === 'full';
  const zone = results.timezone;
  const start = new Date(results.starts_at);
  const end = new Date(results.ends_at);
  const stamp = (date: Date) => `${longDay(date, locale, zone)}, ${clock(date, locale, zone)}`;
  const turnoutPercent = percentText(results.turnout.percentage, locale);
  const logo = results.institution.logo?.md ?? null;

  return (
    <PublicShell
      testId="public-results-page"
      productName={t('app.name')}
      kicker={t('public.kicker')}
      title={results.title}
      titleTestId="public-election-title"
      skip={t('public.skip')}
      hero={
        <>
          <div className="flex flex-wrap items-center gap-3">
            <p
              data-testid="public-institution"
              className="flex min-w-0 items-center gap-3 rounded-full bg-surface py-1.5 pr-5 pl-1.5 text-ink shadow-2"
            >
              <Avatar name={results.institution.name} src={logo} />
              <span className="flex min-w-0 flex-col">
                <span className="text-xs font-semibold tracking-wide text-ink-soft uppercase">
                  {t('public.institutionLabel')}
                </span>
                <b className="font-display text-lg leading-tight font-extrabold break-words">
                  {results.institution.name}
                </b>
              </span>
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <span className={CHIP}>
              <Icon name="calendar" size={18} />
              {t('public.dates', { start: stamp(start), end: stamp(end), zone })}
            </span>
            {results.published_at ? (
              <span className={CHIP}>
                <Icon name="check" size={18} />
                {t('public.published', {
                  day: longDay(new Date(results.published_at), locale, zone),
                })}
              </span>
            ) : null}
          </div>
        </>
      }
    >
      <div className="grid grid-cols-1 items-start gap-4.5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <VoteCard
          tone="figures"
          icon="people"
          title={t('public.turnout.title')}
          data-testid="public-turnout"
          className="lg:col-start-2 lg:row-start-1"
        >
          <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <b className="font-display text-4xl font-extrabold text-ink">
              {t('results.percent', { percent: turnoutPercent })}
            </b>
            <span className="text-md text-ink-2">
              {t(`public.turnout.line.${pluralForm(locale, results.turnout.eligible)}`, {
                voted: results.turnout.voted,
                eligible: results.turnout.eligible,
              })}
            </span>
          </p>
          <GrowBar
            value={results.turnout.percentage / 100}
            trackClassName="h-3"
            className="bg-bar-open"
          />
          {full ? null : (
            <p
              data-testid="public-winners-only"
              className="flex items-start gap-2 text-sm text-ink-soft"
            >
              <span aria-hidden="true" className="mt-0.5 text-primary">
                <Icon name="eye" size={18} />
              </span>
              {t('public.winnersOnly')}
            </p>
          )}
        </VoteCard>

        <section
          aria-label={t('public.ballots')}
          className="min-w-0 lg:col-start-1 lg:row-span-2 lg:row-start-1"
        >
          <Reveal stagger className="flex flex-col gap-4.5">
            {results.ballots.map((ballot, index) => (
              <BallotCard
                key={ballot.id}
                n={index + 1}
                ballot={ballot}
                full={full}
                tone={TONES[index % TONES.length]!}
                t={t}
                locale={locale}
              />
            ))}
          </Reveal>
        </section>

        <VoteCard
          tone="latest"
          icon="globe"
          title={t('public.share.title')}
          data-testid="public-share"
          className="lg:col-start-2 lg:row-start-2"
        >
          <p className="text-base text-ink-soft">{t('public.share.text')}</p>
          <div className="flex flex-wrap gap-2.5">
            {(['whatsapp', 'facebook', 'x'] as const).map((network) => (
              <a
                key={network}
                href={links[network]}
                target="_blank"
                rel="noopener noreferrer"
                data-testid={`public-share-${network}`}
                className={ACTION}
              >
                <Icon name={SHARE_ICONS[network]} size={18} />
                {t(`public.share.${network}`)}
              </a>
            ))}
            <CopyLinkButton
              url={url}
              label={t('public.share.copy')}
              copied={t('public.share.copied')}
              failed={t('public.share.copyFailed')}
              className={ACTION}
            />
          </div>
          <p className="flex min-w-0 flex-col gap-0.5 rounded-md bg-primary-soft px-3.5 py-2.5 text-sm text-ink">
            <span className="font-semibold">{t('public.share.address')}</span>
            <span className="break-all select-all">{url}</span>
          </p>
          <div className="flex flex-col gap-2.5 border-t border-line pt-4">
            <p className="text-sm font-semibold text-ink-soft">{t('public.share.downloads')}</p>
            <a href={reportHref} download data-testid="public-report-download" className={ACTION}>
              <Icon name="list" size={18} />
              {t('public.share.report')}
            </a>
            <a
              href={imageHref}
              download={`results-${results.id}.png`}
              data-testid="public-image-download"
              className={ACTION}
            >
              <Icon name="image" size={18} />
              {t('public.share.image')}
            </a>
          </div>
        </VoteCard>
      </div>

      <ClosingBand
        className="mt-6"
        title={t('public.closing.title', { name: results.institution.name })}
        line={t('public.closing.line')}
        action={
          <a
            href={institutionHref}
            data-testid="public-institution-link"
            className="ui-control bg-accent-gradient inline-flex min-h-12 items-center justify-center gap-2 rounded-full border border-transparent px-6 text-md font-semibold text-deep shadow-button hover:border-accent-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-light"
          >
            <Icon name="school" size={18} />
            {t('public.closing.link')}
          </a>
        }
      />
    </PublicShell>
  );
}

function BallotCard({
  n,
  ballot,
  full,
  tone,
  t,
  locale,
}: {
  n: number;
  ballot: PublicBallot;
  full: boolean;
  tone: HeadTone;
  t: Translate;
  locale: Locale;
}) {
  const tiedNames = tiedCandidates(ballot);
  const nobody = ballot.winners.length === 0 && ballot.tie === null;
  const scope =
    ballot.groups.length > 0
      ? t('public.ballot.groups', { names: joinList(locale, ballot.groups) })
      : t('public.ballot.general');

  return (
    <VoteCard
      tone={tone}
      icon="ballot"
      title={ballot.title}
      data-testid={`public-ballot-${n}`}
      aside={
        <span className="inline-flex h-7.5 items-center gap-1.5 rounded-full bg-glass-line px-3 text-sm font-bold whitespace-nowrap">
          <Icon name="seat" size={16} />
          {t(`public.ballot.seats.${pluralForm(locale, ballot.seats)}`, { count: ballot.seats })}
        </span>
      }
    >
      <p className="inline-flex w-fit items-center gap-2 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-ink">
        <Icon name={ballot.groups.length > 0 ? 'people' : 'globe'} size={16} />
        {scope}
      </p>

      {ballot.candidates.length > 0 ? (
        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {ballot.candidates.map((candidate, index) => {
            const m = index + 1;
            const name = candidateName(candidate);
            const winner = ballot.winners.includes(candidate.id);
            const tied = !winner && isHighlighted(ballot, candidate.id);
            const votes = candidate.votes ?? 0;
            const percent = percentText(candidate.percentage ?? 0, locale);

            return (
              <li
                key={candidate.id}
                data-testid={`public-candidate-${n}-${m}`}
                data-state={winner ? 'winner' : tied ? 'tied' : 'none'}
                className={cx(
                  'flex min-w-0 flex-col gap-2.5 rounded-lg border p-3.5 shadow-1',
                  winner
                    ? 'border-status-open bg-status-open-soft'
                    : tied
                      ? 'border-warm bg-warm-softer'
                      : 'border-line bg-surface',
                )}
              >
                <div className="flex min-w-0 items-center gap-3">
                  <Avatar name={name} src={candidate.photo} />
                  <span className="flex min-w-0 flex-1 flex-col">
                    <b className="text-md leading-tight font-bold break-words text-ink">{name}</b>
                    <span className="text-sm break-words text-ink-soft">
                      {candidate.party ?? t('public.candidate.noParty')}
                    </span>
                  </span>
                </div>
                {full ? (
                  <>
                    <p
                      data-testid={`public-votes-${n}-${m}`}
                      className="flex flex-wrap items-baseline justify-between gap-x-3 text-base text-ink"
                    >
                      <span>
                        <b className="font-display text-xl font-extrabold">{votes}</b>{' '}
                        {t(`public.candidate.votes.${pluralForm(locale, votes)}`)}
                      </span>
                      <b className="font-display text-xl font-extrabold">
                        {t('results.percent', { percent })}
                      </b>
                    </p>
                    <GrowBar
                      value={barOf(candidate.percentage)}
                      trackClassName="h-2.5"
                      className={tied ? 'bg-warm' : 'bg-bar-open'}
                    />
                  </>
                ) : null}
                {winner ? (
                  <span
                    data-testid={`public-winner-${n}-${m}`}
                    className="inline-flex w-fit items-center gap-1.5 rounded-full bg-status-open px-3 py-1 text-sm font-bold text-surface"
                  >
                    <Icon name="check" size={16} />
                    {t('public.candidate.winner')}
                  </span>
                ) : tied ? (
                  <span
                    data-testid={`public-tied-${n}-${m}`}
                    className="inline-flex w-fit items-center gap-1.5 rounded-full bg-warm-soft px-3 py-1 text-sm font-bold text-warm-ink"
                  >
                    <Icon name="warn" size={16} />
                    {t('public.candidate.tied')}
                  </span>
                ) : null}
              </li>
            );
          })}
        </ul>
      ) : null}

      {ballot.tie ? (
        <div
          data-testid={`public-tie-${n}`}
          className="flex gap-3 rounded-lg border border-warm bg-warm-softer p-3.5 text-base text-warm-ink"
        >
          <span aria-hidden="true" className="mt-0.5 shrink-0">
            <Icon name="warn" size={22} />
          </span>
          <div className="flex min-w-0 flex-col gap-1">
            <b className="font-display text-lg font-extrabold">{t('public.tie.title')}</b>
            <p>
              {t(`public.tie.text.${pluralForm(locale, ballot.tie.seats_left)}`, {
                seats: ballot.tie.seats_left,
                names: joinList(locale, tiedNames),
              })}
            </p>
            <p>{t('public.tie.never')}</p>
          </div>
        </div>
      ) : null}

      {nobody ? (
        <p className="flex items-center gap-2 text-base text-ink-soft">
          <Icon name="blank" size={18} />
          {t('public.ballot.none')}
        </p>
      ) : null}

      {full && ballot.blank !== undefined ? (
        <p
          data-testid={`public-blank-${n}`}
          className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-dashed border-line-strong bg-surface-alt px-3.5 py-3 text-base text-ink"
        >
          <span aria-hidden="true" className="text-ink-soft">
            <Icon name="blank" size={20} />
          </span>
          {t(`public.blank.line.${pluralForm(locale, ballot.blank)}`, {
            count: ballot.blank,
            percent: percentText(
              ballot.cast && ballot.cast > 0 ? (ballot.blank / ballot.cast) * 100 : 0,
              locale,
            ),
          })}
        </p>
      ) : null}

      {ballot.note ? (
        <Notice tone="info" data-testid={`public-note-${n}`}>
          <b className="mb-1 flex items-center gap-2 font-bold">
            <Icon name="edit" size={16} />
            {t('public.note')}
          </b>
          {/* The institution's own text, as text: line breaks kept by the style, never markup. */}
          <span className="block break-words whitespace-pre-line">{ballot.note}</span>
        </Notice>
      ) : null}
    </VoteCard>
  );
}
