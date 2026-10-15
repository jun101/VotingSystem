'use client';

import Link from 'next/link';
import { useCallback, useState } from 'react';
import { AdminUserProvider, useAdminUser } from '@/components/admin/AdminUser';
import { focusRing, linkSecondary } from '@/components/admin/classes';
import { ASIDE, DashCard } from '@/components/admin/DashCard';
import { Icon } from '@/components/admin/Icon';
import { Reveal } from '@/components/motion';
import { cx } from '@/components/ui/cx';
import type { CurrentUser, TwoFactorState } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import { ChangePasswordCard } from './ChangePasswordCard';
import { ProfileCard } from './ProfileCard';
import { SecurityCard } from './SecurityCard';
import { SecurityScoreCard } from './SecurityScoreCard';

const QUICK =
  'ui-control lift-sm inline-flex h-12 items-center gap-2.5 rounded-full border pr-5 pl-3.5 text-md font-medium ' +
  focusRing;

/** The two letters of the avatar: the first letters of the first and the last word of the name. */
export function initials(name: string): string {
  const words = name.trim().split(/\s+/).filter(Boolean);

  if (words.length === 0) return '?';

  const first = words[0]!;
  const last = words.length > 1 ? words[words.length - 1]! : '';

  return (Array.from(first)[0]! + (last ? Array.from(last)[0]! : '')).toUpperCase();
}

/**
 * "Mon compte": the identity of the signed-in person in a band, then one white zone with the
 * cards of the profile, the security score, the institution, the password and the second factor.
 * Everything shown comes from what the API already gives (`/auth/me`, `/auth/two-factor`).
 *
 * In the admin area the layout already provides the user. On `/platform` there is no such layout
 * (a platform admin who must set up two-factor first sees this page there): pass `user`.
 */
export function AccountPage({
  twoFactor,
  user,
}: {
  twoFactor: TwoFactorState;
  user?: CurrentUser;
}) {
  if (user) {
    return (
      <AdminUserProvider user={user}>
        <AccountContent twoFactor={twoFactor} />
      </AdminUserProvider>
    );
  }

  return <AccountContent twoFactor={twoFactor} />;
}

function AccountContent({ twoFactor }: { twoFactor: TwoFactorState }) {
  const { t, tIfAny } = useI18n();
  const user = useAdminUser();
  const [state, setState] = useState(twoFactor);
  const onState = useCallback((next: TwoFactorState) => setState(next), []);
  const role = tIfAny(`admin.roles.${user.role}`);
  const withInstitution = user.institution !== null;

  return (
    <Reveal stagger data-testid="account-page" className="flex flex-col gap-4.5">
      <section
        data-testid="account-hero"
        className="bg-hero cover-sweep flex flex-wrap items-center gap-x-7 gap-y-5 rounded-xl px-5 py-6 text-surface shadow-2 md:px-8"
      >
        <span
          aria-hidden="true"
          className="avatar-ring relative z-10 flex size-24 shrink-0 items-center justify-center rounded-full border-[3px] border-glass-line bg-glass font-display text-[34px] font-extrabold"
        >
          {initials(user.name)}
        </span>

        <div className="relative z-10 min-w-0">
          <h2
            data-testid="account-name"
            className="text-2xl break-words text-surface md:text-[32px]"
          >
            {user.name}
          </h2>
          <p data-testid="account-email" className="mt-0.5 text-lg break-all text-surface">
            {user.email}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {role ? (
              <span className={ASIDE} data-testid="account-role">
                <Icon name="user" size={16} />
                {role}
              </span>
            ) : null}
            {user.institution ? (
              <span className={ASIDE} data-testid="account-institution-chip">
                <Icon name="school" size={16} />
                {user.institution.name}
              </span>
            ) : null}
            <span
              className={cx(ASIDE, user.email_verified ? 'bg-teal-soft text-teal' : '')}
              data-testid="account-verified-chip"
            >
              <Icon name={user.email_verified ? 'check' : 'warn'} size={16} />
              {t(user.email_verified ? 'account.page.verified' : 'account.page.notVerified')}
            </span>
          </div>
        </div>

        <div className="relative z-10 flex flex-wrap gap-2.5 md:ml-auto">
          <a
            href="#account-password"
            data-testid="account-quick-password"
            className={cx(QUICK, 'border-glass-line bg-glass text-surface hover:bg-glass-line')}
          >
            <Icon name="key" size={22} />
            {t('account.page.quick.password')}
          </a>
          {state.enabled ? null : (
            <a
              href="#account-two-factor"
              data-testid="account-quick-two-factor"
              className={cx(QUICK, 'border-accent bg-accent font-bold text-deep')}
            >
              <Icon name="shield" size={22} />
              {t('account.page.quick.twoFactor')}
            </a>
          )}
        </div>
      </section>

      <section
        data-testid="account-zone"
        aria-labelledby="account-zone-title"
        className="rounded-2xl border border-line bg-surface p-5 shadow-1"
      >
        <div className="mx-1 mb-4 flex flex-wrap items-center gap-x-3">
          <span className="text-primary">
            <Icon name="user" size={24} />
          </span>
          <h2 id="account-zone-title" className="text-xl">
            {t('account.page.zone.title')}
          </h2>
          <small className="text-base font-medium text-ink-soft">
            {t('account.page.zone.lede')}
          </small>
        </div>

        <div className="rise-stagger grid grid-cols-1 gap-4.5 md:grid-cols-2 2xl:grid-cols-12">
          <DashCard
            card="account-profile"
            icon="user"
            title={t('account.page.profile.title')}
            outerClassName={withInstitution ? '2xl:col-span-4' : '2xl:col-span-6'}
          >
            <ProfileCard />
          </DashCard>

          <DashCard
            card="account-security"
            icon="shield"
            title={t('account.page.score.title')}
            outerClassName={withInstitution ? '2xl:col-span-4' : '2xl:col-span-6'}
          >
            <SecurityScoreCard twoFactor={state} />
          </DashCard>

          {user.institution ? (
            <DashCard
              card="account-institution"
              icon="school"
              title={t('account.page.institution.title')}
              outerClassName="md:col-span-2 2xl:col-span-4"
            >
              <div className="flex flex-col gap-3.5">
                <div className="flex items-center gap-3 rounded-lg bg-line-soft p-3.5">
                  <span
                    aria-hidden="true"
                    className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary"
                  >
                    <Icon name="school" size={22} />
                  </span>
                  <span className="min-w-0">
                    <b className="block text-lg break-words" data-testid="account-institution-name">
                      {user.institution.name}
                    </b>
                    <small className="text-base text-ink-soft">
                      {t('account.page.institution.yours')}
                    </small>
                  </span>
                </div>
                {role ? (
                  <div className="flex items-center gap-3 rounded-lg bg-line-soft p-3.5">
                    <span
                      aria-hidden="true"
                      className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warm-soft text-warm"
                    >
                      <Icon name="user" size={22} />
                    </span>
                    <span className="min-w-0">
                      <b className="block text-lg">{role}</b>
                      <small className="text-base text-ink-soft">
                        {tIfAny(`account.page.institution.roles.${user.role}`) ?? ''}
                      </small>
                    </span>
                  </div>
                ) : null}
                <Link
                  href="/admin/institution"
                  data-testid="account-institution-link"
                  className={cx(linkSecondary, 'mt-auto gap-2 self-start')}
                >
                  <Icon name="open" size={20} />
                  {t('account.page.institution.open')}
                </Link>
              </div>
            </DashCard>
          ) : null}

          <DashCard
            id="account-password"
            card="account-password"
            icon="key"
            title={t('account.changePassword.title')}
            outerClassName="scroll-mt-6 2xl:col-span-6"
          >
            <ChangePasswordCard plain twoFactorEnabled={state.enabled} />
          </DashCard>

          <DashCard
            id="account-two-factor"
            card="account-two-factor"
            icon="phone"
            title={t('account.security.label')}
            aside={
              <span className={ASIDE} data-testid="account-two-factor-aside">
                {t(state.enabled ? 'account.security.on' : 'account.security.off')}
              </span>
            }
            outerClassName="scroll-mt-6 2xl:col-span-6"
          >
            <SecurityCard plain initial={twoFactor} onState={onState} />
          </DashCard>
        </div>
      </section>
    </Reveal>
  );
}
