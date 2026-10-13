'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Notice } from '@/components/ui';
import { ApiError, errorText } from '@/lib/api/errors';
import { fetchBallots, VoteError } from '@/lib/api/vote';
import { joinList, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { VoteCard } from './VoteCard';
import { useVote, useVoterBallots } from './VoteProvider';
import { VoteShell } from './VoteShell';
import { whenText } from './voteTime';

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toLocaleUpperCase())
    .join('');
}

/** M2: "Is this you?", with the ballots the voter takes part in. */
export function Identity() {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const { election, info, signOut, leave, setPhase, setBallots } = useVote();
  const { ballots, failure, retry } = useVoterBallots();
  const [leaving, setLeaving] = useState(false);

  if (!ballots) {
    return (
      <VoteShell
        testId="vote-identity-loading"
        hero={<h1 className="text-3xl text-surface md:text-4xl">{t('vote.identity.kicker')}</h1>}
      >
        {failure ? (
          <Notice tone="danger" role="alert">
            <p>{errorText(failure, tIfAny)}</p>
            <Button variant="secondary" size="voter" className="mt-3" onClick={retry}>
              {t('vote.code.submit')}
            </Button>
          </Notice>
        ) : (
          <VoteCard tone="getting-started" icon="user" title={t('vote.identity.loading')}>
            <div className="shimmer h-16 rounded-md" />
            <div className="shimmer h-12 rounded-md" />
          </VoteCard>
        )}
      </VoteShell>
    );
  }

  const { voter, ballots: list } = ballots;
  const none = list.length === 0;

  // The session is asked once more before the first position: an election closed since the code was
  // entered is told here, in the voter's language, not at the end of the vote.
  async function proceed() {
    setLeaving(true);

    try {
      setBallots(await fetchBallots(election));
      router.push(`/vote/${election}/ballot/1`);
    } catch (error) {
      const problem = error instanceof ApiError ? error : new ApiError(0, 'network');

      if (problem.code === 'voter_session_expired') return leave('expired');
      if (problem.code === 'already_voted') return leave('alreadyVoted');

      if (problem.code === 'election_not_open') {
        const phase = error instanceof VoteError ? error.phase : null;

        if (phase) setPhase(phase);

        return leave(null);
      }

      // A network failure or a limit: the ballots read before are still good, the voter goes on.
      router.push(`/vote/${election}/ballot/1`);
    }
  }

  async function notMe() {
    setLeaving(true);
    await signOut(null);
  }

  const ends = whenText(info.ends_at, locale, info.timezone, t);

  const hero = (
    <div className="flex flex-col gap-3">
      <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
        <Icon name="user" size={18} />
        {t('vote.identity.kicker')}
      </p>
      <h1 className="text-2xl text-surface md:text-4xl">{info.title}</h1>
      <ul className="flex flex-wrap gap-2 text-base font-medium">
        {(
          [
            ['clock', t('vote.identity.closes', { when: ends })],
            ['lock', t('vote.identity.secret')],
            ['flag', t('vote.identity.step')],
          ] as const
        ).map(([icon, text]) => (
          <li key={icon} className="flex items-center gap-2 rounded-full bg-glass px-3 py-1">
            <Icon name={icon} size={16} />
            {text}
          </li>
        ))}
      </ul>
    </div>
  );

  return (
    <VoteShell hero={hero} testId="vote-identity">
      <div className="flex flex-col gap-4">
        <section className="id-card flex min-w-0 flex-col overflow-hidden rounded-lg border-2 border-primary bg-surface shadow-3">
          <div className="id-band flex flex-col items-center gap-5 px-5 py-6 text-center sm:flex-row sm:px-8 sm:text-left">
            <span
              aria-hidden="true"
              className="id-avatar cand-avatar relative flex size-28 shrink-0 items-center justify-center rounded-full font-display text-4xl font-extrabold text-surface sm:size-32 sm:text-5xl"
              data-sex="male"
            >
              {initials(voter.full_name)}
              <span className="id-check absolute -right-0.5 -bottom-0.5 flex size-9 items-center justify-center rounded-full border-4 border-surface bg-status-open text-surface">
                <Icon name="check" size={16} />
              </span>
            </span>
            <div className="flex min-w-0 flex-1 flex-col items-center gap-2 sm:items-start">
              <h2
                data-testid="vote-identity-name"
                className="font-display text-3xl font-extrabold break-words text-ink sm:text-4xl"
              >
                {voter.full_name}
              </h2>
              <p
                data-testid="vote-identity-group"
                className="flex w-fit items-center gap-2 rounded-full border border-primary-line bg-surface px-3 py-1 text-base font-medium text-ink-2"
              >
                <Icon name="people" size={16} />
                {voter.group
                  ? t('vote.identity.group', { group: voter.group })
                  : t('vote.identity.noGroup')}
              </p>
            </div>
            {none ? null : (
              <div className="flex shrink-0 flex-col items-center rounded-md bg-primary px-5 py-3 text-surface">
                <span className="font-display text-4xl font-extrabold">{list.length}</span>
                <span className="text-sm font-medium">
                  {t(`vote.identity.badge.${pluralForm(locale, list.length)}`)}
                </span>
              </div>
            )}
          </div>

          <div className="flex flex-col gap-4 p-5 sm:p-8">
            {none ? (
              <div>
                <p className="text-md font-bold text-ink">{t('vote.identity.noneTitle')}</p>
                <p className="text-md text-ink-2">{t('vote.identity.noneText')}</p>
              </div>
            ) : (
              <ol className="grid gap-3 sm:grid-cols-2">
                {list.map((ballot, index) => (
                  <li
                    key={ballot.id}
                    data-testid={`vote-identity-ballot-${index + 1}`}
                    style={{ animationDelay: `${index * 120}ms` }}
                    className="id-rise flex items-start gap-3 rounded-md border border-line bg-surface-alt p-3"
                  >
                    <span
                      aria-hidden="true"
                      className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary font-display text-base font-extrabold text-surface"
                    >
                      {index + 1}
                    </span>
                    <span className="flex min-w-0 flex-col gap-1">
                      <span className="text-md font-bold break-words text-ink">{ballot.title}</span>
                      <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-soft">
                        <span className="inline-flex items-center gap-1">
                          <Icon name="seat" size={14} />
                          {t(`vote.identity.seats.${pluralForm(locale, ballot.seats)}`, {
                            count: ballot.seats,
                          })}
                        </span>
                        <span className="inline-flex items-center gap-1">
                          <Icon name="people" size={14} />
                          {ballot.scope === 'group' && ballot.group_names.length > 0
                            ? t('vote.identity.forGroups', {
                                groups: joinList(locale, ballot.group_names),
                              })
                            : t('vote.identity.forAll')}
                        </span>
                      </span>
                    </span>
                  </li>
                ))}
              </ol>
            )}

            <p className="flex items-start gap-2 rounded-md bg-warm-softer p-3 text-base text-warm-ink">
              <span className="mt-0.5">
                <Icon name="warn" size={18} />
              </span>
              {t('vote.identity.hint')}
            </p>

            <div className="flex flex-col gap-3 sm:flex-row-reverse">
              {none ? null : (
                <Button
                  variant="primary"
                  size="voter"
                  shimmer
                  disabled={leaving}
                  data-testid="vote-identity-yes"
                  className="min-h-14 w-full text-lg sm:flex-[1.4]"
                  onClick={() => void proceed()}
                >
                  <span className="inline-flex items-center gap-2">
                    {t('vote.identity.yes')}
                    <Icon name="check" size={20} />
                  </span>
                </Button>
              )}
              <Button
                variant="secondary"
                size="voter"
                loading={leaving}
                data-testid="vote-identity-no"
                className="min-h-14 w-full text-lg sm:flex-1"
                onClick={() => void notMe()}
              >
                {t('vote.identity.no')}
              </Button>
            </div>
          </div>
        </section>
      </div>
    </VoteShell>
  );
}
