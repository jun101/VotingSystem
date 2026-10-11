import Link from 'next/link';
import { Icon } from '@/components/admin/Icon';
import { Reveal } from '@/components/motion';
import { VoteCard } from '@/components/vote/VoteCard';
import type { PublicInstitution } from '@/lib/api/public';
import { longDay, pluralForm } from '@/lib/format/electionDates';
import type { Locale } from '@/lib/i18n/locale';
import type { Translate } from '@/lib/i18n/messages';
import { Avatar } from './Avatar';
import { PublicShell } from './PublicShell';

const CHIP =
  'inline-flex min-h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass py-1 pr-3.5 pl-2.5 text-base font-medium text-surface';

/** The institution's public page: who it is and a card per published election, newest first. */
export function InstitutionView({
  profile,
  t,
  locale,
}: {
  profile: PublicInstitution;
  t: Translate;
  locale: Locale;
}) {
  const elections = profile.elections;

  return (
    <PublicShell
      testId="institution-page"
      productName={t('app.name')}
      kicker={t('public.institution.kicker')}
      kickerIcon="school"
      title={profile.name}
      titleTestId="institution-name"
      skip={t('public.skip')}
      hero={
        <div className="flex flex-wrap items-center gap-3">
          <Avatar
            name={profile.name}
            src={profile.logo?.md ?? null}
            size="lg"
            className="ring-2 ring-surface"
          />
          <span className={CHIP}>
            <Icon name="school" size={18} />
            {t(`public.institution.type.${profile.type}`)}
          </span>
          {profile.city ? (
            <span className={CHIP}>
              <Icon name="flag" size={18} />
              {profile.city}
            </span>
          ) : null}
          <span className={CHIP}>
            <Icon name="ballot" size={18} />
            {t(`public.institution.count.${pluralForm(locale, elections.length)}`, {
              count: elections.length,
            })}
          </span>
        </div>
      }
    >
      <div className="grid grid-cols-1 items-start gap-4.5 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <VoteCard
          tone="activity"
          icon="ballot"
          title={t('public.institution.elections')}
          className="lg:col-start-1 lg:row-start-1"
        >
          {elections.length > 0 ? (
            <Reveal stagger className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {elections.map((election, index) => (
                <Link
                  key={election.id}
                  href={`/results/${election.id}`}
                  data-testid={`institution-election-${index + 1}`}
                  className="lift ui-control flex min-w-0 flex-col gap-2 rounded-lg border border-line bg-surface p-4 shadow-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  <span className="flex items-start gap-3">
                    <span
                      aria-hidden="true"
                      className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
                    >
                      <Icon name="flag" size={20} />
                    </span>
                    <b className="min-w-0 font-display text-lg leading-tight font-extrabold break-words text-ink">
                      {election.title}
                    </b>
                  </span>
                  <span className="flex items-center gap-2 text-sm text-ink-soft">
                    <Icon name="calendar" size={16} />
                    {election.published_at
                      ? t('public.institution.published', {
                          day: longDay(new Date(election.published_at), locale, election.timezone),
                        })
                      : null}
                  </span>
                  <span className="mt-auto inline-flex items-center gap-1.5 text-base font-bold text-primary-hover">
                    {t('public.institution.open')}
                    <Icon name="right" size={16} />
                  </span>
                </Link>
              ))}
            </Reveal>
          ) : (
            <div
              data-testid="institution-empty"
              className="flex flex-col items-center gap-2 px-3 py-8 text-center"
            >
              <span
                aria-hidden="true"
                className="flex size-14 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
              >
                <Icon name="ballot" size={28} />
              </span>
              <p className="text-md font-semibold text-ink">{t('public.institution.empty')}</p>
              <p className="text-base text-ink-soft">{t('public.institution.emptyHelp')}</p>
            </div>
          )}
        </VoteCard>

        <VoteCard
          tone="latest"
          icon="school"
          title={t('public.institution.description')}
          className="lg:col-start-2 lg:row-start-1"
        >
          <div className="flex items-center gap-3">
            <Avatar name={profile.name} src={profile.logo?.md ?? null} />
            <b className="min-w-0 font-display text-lg font-extrabold break-words text-ink">
              {profile.name}
            </b>
          </div>
          {profile.description ? (
            <p className="text-base break-words whitespace-pre-line text-ink-soft">
              {profile.description}
            </p>
          ) : null}
          {profile.city ? (
            <p className="flex items-center gap-2 text-base text-ink">
              <Icon name="flag" size={18} />
              {profile.city}
            </p>
          ) : null}
        </VoteCard>
      </div>
    </PublicShell>
  );
}
