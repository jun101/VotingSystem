'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { useAdminUser } from '@/components/admin/AdminUser';
import { Icon, type IconName } from '@/components/admin/Icon';
import { Notice, Pill } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { updateLanguage } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

function Line({
  icon,
  label,
  value,
  end,
  testId,
}: {
  icon: IconName;
  label: string;
  value: string;
  end?: React.ReactNode;
  testId: string;
}) {
  return (
    <div className="flex items-center gap-3 rounded-lg px-2 py-1.5">
      <span
        aria-hidden="true"
        className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary"
      >
        <Icon name={icon} size={20} />
      </span>
      <span className="min-w-0 flex-1 text-md font-medium">
        {label}
        <small data-testid={testId} className="block text-base font-normal break-all text-ink-soft">
          {value}
        </small>
      </span>
      {end}
    </div>
  );
}

/** The name, the address and the language of the person; the language can be changed here. */
export function ProfileCard() {
  const { t, tIfAny, locale } = useI18n();
  const user = useAdminUser();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [, startTransition] = useTransition();

  async function choose(language: 'fr' | 'en') {
    if (language === locale || busy) return;

    setBusy(true);
    setProblem(null);

    try {
      await updateLanguage(language);
      startTransition(() => router.refresh());
    } catch (error) {
      if (error instanceof ApiError && error.code === 'unauthenticated') {
        router.push('/login');

        return;
      }

      setProblem(
        `${t('admin.language.error')} ${errorText(error instanceof ApiError ? error : new ApiError(0, 'unknown'), tIfAny)}`,
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-2.5" data-testid="profile-card">
      <Line
        icon="user"
        label={t('account.page.profile.name')}
        value={user.name}
        testId="profile-name"
      />
      <Line
        icon="mail"
        label={t('account.page.profile.email')}
        value={user.email}
        testId="profile-email"
        end={
          <Pill tone={user.email_verified ? 'teal' : 'neutral'}>
            {t(user.email_verified ? 'account.page.verified' : 'account.page.notVerified')}
          </Pill>
        }
      />
      <div className="flex flex-col gap-2 px-2 pt-1.5">
        <span className="flex items-center gap-3 text-md font-medium">
          <span
            aria-hidden="true"
            className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary"
          >
            <Icon name="globe" size={20} />
          </span>
          {t('account.page.profile.language')}
        </span>
        <div
          role="group"
          aria-label={t('account.page.profile.language')}
          className="flex gap-2 pl-13"
        >
          {(['fr', 'en'] as const).map((code) => (
            <button
              key={code}
              type="button"
              disabled={busy}
              aria-pressed={locale === code}
              onClick={() => void choose(code)}
              data-testid={`profile-language-${code}`}
              className={cx(
                'ui-control min-h-11 rounded-full border px-4 text-md font-medium',
                locale === code
                  ? 'border-primary bg-primary text-surface'
                  : 'border-line bg-surface text-ink hover:bg-primary-soft',
              )}
            >
              {t(`account.page.profile.${code === 'fr' ? 'french' : 'english'}`)}
            </button>
          ))}
        </div>
      </div>
      {problem ? (
        <Notice tone="danger" role="alert" data-testid="profile-language-error">
          {problem}
        </Notice>
      ) : null}
    </div>
  );
}
