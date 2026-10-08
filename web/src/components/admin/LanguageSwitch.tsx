'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { updateLanguage } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { navyFocus } from './classes';
import { Icon } from './Icon';

const GLOBE =
  'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18';

/**
 * The FR/EN switch of the side menu. It stores the other language on the user
 * (`PATCH /auth/me`), then asks the server to render the page again: no full reload. When
 * the change fails the old language stays and the reason is shown.
 */
export function LanguageSwitch() {
  const { locale, t, tIfAny } = useI18n();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [, startTransition] = useTransition();

  async function change() {
    setBusy(true);
    setProblem(null);

    try {
      await updateLanguage(locale === 'fr' ? 'en' : 'fr');
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
    <div className="flex flex-col gap-2">
      <button
        type="button"
        data-testid="language-switch"
        data-language={locale}
        disabled={busy}
        onClick={change}
        className={`ui-control flex min-h-11 w-full items-center gap-2 rounded border border-ink-2 px-3 text-base font-semibold text-surface hover:bg-navy-raised disabled:text-ink-muted ${navyFocus}`}
      >
        <Icon path={GLOBE} />
        {t('admin.language.switch')}
      </button>
      {problem ? (
        <p role="alert" data-testid="language-error" className="text-sm text-warm-soft">
          {problem}
        </p>
      ) : null}
    </div>
  );
}
