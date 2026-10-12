'use client';

import { Icon } from '@/components/admin/Icon';
import { Button } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import type { PlatformInstitution } from '@/lib/api/platform';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { dayOf, electionChips, electionTotal, STATUS_TONES } from './platformModel';

/**
 * One institution as a card: name, status chip (colour and icon, never colour alone), the numbers
 * the platform may see (people, voters, elections by status), the two dates and, when suspended,
 * the reason. `n` in the test ids is the 1-based place on the page, newest first.
 */
export function InstitutionCard({
  institution,
  n,
  onSuspend,
  onReactivate,
}: {
  institution: PlatformInstitution;
  n: number;
  onSuspend: () => void;
  onReactivate: () => void;
}) {
  const { t, locale } = useI18n();
  const suspended = institution.status === 'suspended';
  const chips = electionChips(institution.counts.elections);
  const total = electionTotal(institution.counts.elections);
  const created = dayOf(institution.created_at, locale);
  const lastSignIn = dayOf(institution.last_sign_in_at, locale);

  const tiles = [
    {
      icon: 'user' as const,
      count: institution.counts.users,
      label: t(`platform.counts.users.${pluralForm(locale, institution.counts.users)}`),
    },
    {
      icon: 'people' as const,
      count: institution.counts.voters,
      label: t(`platform.counts.voters.${pluralForm(locale, institution.counts.voters)}`),
    },
    {
      icon: 'ballot' as const,
      count: total,
      label: t(`platform.counts.elections.${pluralForm(locale, total)}`),
    },
  ];

  return (
    <li
      data-testid={`platform-institution-${n}`}
      className="lift flex min-w-0 flex-col gap-4 rounded-lg border border-line bg-surface p-4 shadow-1 md:p-5"
    >
      <div className="flex min-w-0 items-start gap-3">
        <span
          aria-hidden="true"
          className={cx(
            'flex size-12 shrink-0 items-center justify-center rounded-full',
            suspended ? 'bg-warm-soft text-warm-ink' : 'bg-primary-soft text-primary-hover',
          )}
        >
          <Icon name="school" size={24} />
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-1.5">
          <h2
            data-testid={`platform-name-${n}`}
            className="font-display text-lg leading-tight font-extrabold break-words text-ink"
          >
            {institution.name}
          </h2>
          <div className="flex flex-wrap items-center gap-2">
            <span
              data-testid={`platform-status-${n}`}
              data-status={institution.status}
              className={cx(
                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold',
                suspended ? 'bg-warm-soft text-danger' : 'bg-status-open-soft text-status-open',
              )}
            >
              <Icon name={suspended ? 'lock' : 'check'} size={14} />
              {t(`platform.status.${institution.status}`)}
            </span>
            <span className="inline-flex items-center rounded-full bg-line-soft px-3 py-1 text-sm font-medium text-ink-2">
              {t(`platform.type.${institution.type}`)}
            </span>
          </div>
        </div>
      </div>

      <div data-testid={`platform-counts-${n}`} className="flex flex-col gap-3">
        <ul className="grid grid-cols-3 gap-2">
          {tiles.map((tile) => (
            <li
              key={tile.icon}
              className="flex min-w-0 flex-col items-center gap-0.5 rounded-md bg-canvas px-1.5 py-2.5 text-center"
            >
              <span className="text-primary-hover">
                <Icon name={tile.icon} size={18} />
              </span>
              <b className="font-display text-xl leading-none font-extrabold text-ink">
                {tile.count}
              </b>
              <span className="max-w-full text-sm leading-tight break-words text-ink-soft">
                {tile.label}
              </span>
            </li>
          ))}
        </ul>
        {chips.length > 0 ? (
          <ul className="flex flex-wrap gap-1.5" aria-label={t('platform.counts.byStatus')}>
            {chips.map((chip) => (
              <li
                key={chip.status}
                className={cx(
                  'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold',
                  STATUS_TONES[chip.status],
                )}
              >
                <span>{chip.count}</span>
                {t(`platform.electionStatus.${chip.status}`)}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-ink-soft">{t('platform.counts.noElection')}</p>
        )}
      </div>

      <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-sm text-ink-soft sm:grid-cols-2">
        <div className="flex items-center gap-1.5">
          <Icon name="calendar" size={14} />
          <dt className="sr-only">{t('platform.created')}</dt>
          <dd>{t('platform.createdOn', { day: created ?? '-' })}</dd>
        </div>
        <div className="flex items-center gap-1.5">
          <Icon name="clock" size={14} />
          <dt className="sr-only">{t('platform.lastSignIn')}</dt>
          <dd>
            {lastSignIn
              ? t('platform.lastSignInOn', { day: lastSignIn })
              : t('platform.neverSignedIn')}
          </dd>
        </div>
      </dl>

      {suspended && institution.suspension_reason ? (
        <div
          data-testid={`platform-reason-${n}`}
          className="flex flex-col gap-1 rounded border border-warm bg-warm-softer p-3 text-warm-ink"
        >
          <span className="text-sm font-bold">{t('platform.reasonTitle')}</span>
          <span className="text-base break-words">{institution.suspension_reason}</span>
          {institution.suspended_at ? (
            <span className="text-sm">
              {t('platform.suspendedOn', { day: dayOf(institution.suspended_at, locale) ?? '-' })}
            </span>
          ) : null}
        </div>
      ) : null}

      <div className="flex justify-end">
        {suspended ? (
          <Button
            variant="primary"
            data-testid={`platform-reactivate-${n}`}
            onClick={onReactivate}
            className="max-md:w-full"
          >
            {t('platform.reactivate')}
          </Button>
        ) : (
          <Button
            variant="danger"
            data-testid={`platform-suspend-${n}`}
            onClick={onSuspend}
            className="max-md:w-full"
          >
            {t('platform.suspend')}
          </Button>
        )}
      </div>
    </li>
  );
}
