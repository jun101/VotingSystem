'use client';

import { useEffect, useRef, useState } from 'react';
import { Button, Notice } from '@/components/ui';
import { endImpersonation } from '@/lib/api/browser';
import { ApiError, waitText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { loadPage } from '@/lib/navigation';
import { Icon } from './Icon';

/**
 * Shown on every admin page while a platform admin acts as an institution's owner: who and where,
 * that the actions are audited, and the way out, which returns to `/platform`.
 */
export function ImpersonationBanner({ by, institution }: { by: string; institution: string }) {
  const { t, tIfAny } = useI18n();
  const [ending, setEnding] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const alive = useRef(true);

  useEffect(() => {
    alive.current = true;

    return () => {
      alive.current = false;
    };
  }, []);

  async function end() {
    if (ending) return;

    setEnding(true);
    setProblem(null);

    try {
      await endImpersonation();
    } catch (caught) {
      if (!alive.current) return;

      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      // Not acting any more (ended elsewhere): the way out is the same.
      if (error.code !== 'not_impersonating') {
        if (error.code === 'unauthenticated') {
          loadPage('/login');

          return;
        }

        setProblem(waitText(error, tIfAny));
        setEnding(false);

        return;
      }
    }

    // A full navigation: the session is the admin's again and the server layout must read it.
    loadPage('/platform');
  }

  return (
    <div
      role="region"
      aria-label={t('admin.impersonation.title', { institution })}
      data-testid="impersonation-banner"
      className="border-b border-warm bg-warm-soft px-4 py-3 text-warm-ink md:px-7"
    >
      <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span
            aria-hidden="true"
            className="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface text-warm-ink"
          >
            <Icon name="eye" size={22} />
          </span>
          <div className="flex min-w-0 flex-col gap-0.5">
            <b className="font-display text-base font-extrabold break-words">
              {t('admin.impersonation.title', { institution })}
            </b>
            <p className="text-sm break-words">{t('admin.impersonation.text', { name: by })}</p>
          </div>
        </div>
        <Button
          variant="secondary"
          data-testid="impersonation-end"
          disabled={ending}
          aria-busy={ending || undefined}
          onClick={() => void end()}
          className="max-sm:w-full"
        >
          <Icon name="left" size={18} />
          {t(ending ? 'admin.impersonation.ending' : 'admin.impersonation.end')}
        </Button>
      </div>
      {problem ? (
        <Notice tone="danger" role="alert" data-testid="impersonation-error" className="mt-3">
          {problem}
        </Notice>
      ) : null}
    </div>
  );
}
