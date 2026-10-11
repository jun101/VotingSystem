'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Notice } from '@/components/ui';
import { errorText } from '@/lib/api/errors';
import { joinList, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { VoteCard } from './VoteCard';
import { useVote, useVoterBallots } from './VoteProvider';
import { VoteShell } from './VoteShell';

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
  const { election, signOut } = useVote();
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

  async function notMe() {
    setLeaving(true);
    await signOut(null);
  }

  const hero = (
    <div className="flex flex-col gap-2">
      <p className="flex items-center gap-2 text-sm font-semibold tracking-wide text-hero-ink-soft uppercase">
        <Icon name="user" size={18} />
        {t('vote.identity.kicker')}
      </p>
      <h1 data-testid="vote-identity-name" className="text-3xl text-surface md:text-4xl">
        {voter.full_name}
      </h1>
      <p
        data-testid="vote-identity-group"
        className="flex w-fit items-center gap-2 rounded-full bg-glass px-3 py-1 text-base font-medium"
      >
        <Icon name="people" size={16} />
        {voter.group
          ? t('vote.identity.group', { group: voter.group })
          : t('vote.identity.noGroup')}
      </p>
    </div>
  );

  return (
    <VoteShell hero={hero} testId="vote-identity">
      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <VoteCard tone="getting-started" icon="user" title={t('vote.identity.kicker')}>
          <div className="flex items-center gap-4">
            <span
              aria-hidden="true"
              className="cand-avatar flex size-20 shrink-0 items-center justify-center rounded-full font-display text-3xl font-extrabold"
              data-sex="male"
            >
              {initials(voter.full_name)}
            </span>
            <p className="min-w-0 text-md text-ink-2">
              {none
                ? t('vote.identity.noneText')
                : t(`vote.identity.count.${pluralForm(locale, list.length)}`, {
                    count: list.length,
                  })}
            </p>
          </div>
          <p className="rounded-md bg-warm-softer p-3 text-base text-warm-ink">
            {t('vote.identity.hint')}
          </p>
          <div className="mt-auto flex flex-col gap-3 sm:flex-row-reverse">
            {none ? null : (
              <Button
                variant="primary"
                size="voter"
                shimmer
                disabled={leaving}
                data-testid="vote-identity-yes"
                className="w-full sm:flex-1"
                onClick={() => router.push(`/vote/${election}/ballot/1`)}
              >
                {t('vote.identity.yes')}
              </Button>
            )}
            <Button
              variant="secondary"
              size="voter"
              loading={leaving}
              data-testid="vote-identity-no"
              className="w-full sm:flex-1"
              onClick={() => void notMe()}
            >
              {t('vote.identity.no')}
            </Button>
          </div>
        </VoteCard>

        <VoteCard tone="figures" icon="ballot" title={t('vote.identity.ballotsTitle')}>
          {none ? (
            <p className="text-md text-ink-2">{t('vote.identity.noneTitle')}</p>
          ) : (
            <ol className="flex flex-col gap-3">
              {list.map((ballot, index) => (
                <li
                  key={ballot.id}
                  data-testid={`vote-identity-ballot-${index + 1}`}
                  className="flex items-start gap-3 rounded-md border border-line bg-surface-alt p-3"
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
        </VoteCard>
      </div>
    </VoteShell>
  );
}
