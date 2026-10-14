'use client';

import { Icon, type IconName } from '@/components/admin/Icon';
import { useAdminUser } from '@/components/admin/AdminUser';
import { cx } from '@/components/ui/cx';
import type { TwoFactorState } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';

type Step = { key: 'emailVerified' | 'twoFactor' | 'recoveryCodes'; done: boolean; icon: IconName };

/** What counts toward the score: three facts the API gives, equal weight. */
export function securitySteps(emailVerified: boolean, twoFactor: TwoFactorState): Step[] {
  const codes = twoFactor.recovery_codes_left ?? 0;

  return [
    { key: 'emailVerified', done: emailVerified, icon: 'mail' },
    { key: 'twoFactor', done: twoFactor.enabled, icon: 'phone' },
    { key: 'recoveryCodes', done: twoFactor.enabled && codes > 0, icon: 'key' },
  ];
}

/** The security level of the account: a ring and the three things that make it up. */
export function SecurityScoreCard({ twoFactor }: { twoFactor: TwoFactorState }) {
  const { t } = useI18n();
  const user = useAdminUser();
  const steps = securitySteps(user.email_verified, twoFactor);
  const done = steps.filter((step) => step.done).length;
  const percent = Math.round((done / steps.length) * 100);

  return (
    <div className="flex flex-col gap-4" data-testid="security-score">
      <div className="flex items-center gap-5">
        <div
          data-testid="security-score-ring"
          role="progressbar"
          aria-label={t('account.page.score.progress')}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          className="ring-spin relative grid size-28 shrink-0 place-items-center rounded-full"
          style={{
            backgroundImage: `conic-gradient(var(--color-status-open) 0 ${percent}%, var(--color-primary-soft) 0)`,
          }}
        >
          <span aria-hidden="true" className="absolute size-22 rounded-full bg-surface" />
          <b className="relative font-display text-[28px] font-extrabold text-ink">
            {t('account.page.score.percent', { percent })}
          </b>
        </div>
        <p className="text-base text-ink-soft">
          {t(done === steps.length ? 'account.page.score.full' : 'account.page.score.lead', {
            done,
            total: steps.length,
          })}
        </p>
      </div>

      <ul className="flex flex-col gap-1.5">
        {steps.map((step) => (
          <li
            key={step.key}
            data-testid={`security-step-${step.key}`}
            data-done={step.done ? 'true' : 'false'}
            className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-md font-medium"
          >
            <span
              aria-hidden="true"
              className={cx(
                'flex size-9 shrink-0 items-center justify-center rounded-full',
                step.done ? 'bg-status-open-soft text-status-open' : 'bg-warm-soft text-warm',
              )}
            >
              <Icon name={step.done ? 'check' : step.icon} size={19} />
            </span>
            <span className="min-w-0 flex-1">
              {t(`account.page.score.steps.${step.key}`)}
              <small className="block text-base font-normal text-ink-soft">
                {t(step.done ? 'account.page.score.done' : `account.page.score.hints.${step.key}`)}
              </small>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
