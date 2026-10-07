'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { verifyEmail } from '@/lib/api/browser';
import { ApiError } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

type State = 'pending' | 'success' | 'invalid' | 'expired' | 'error';

/**
 * One call per token for the life of the page. A token works once: the call is made again
 * if the component mounts twice (development's strict mode), and would then answer
 * "invalid" to the second.
 */
const calls = new Map<string, Promise<State>>();

function verifyOnce(token: string): Promise<State> {
  let call = calls.get(token);

  if (!call) {
    call = verifyEmail(token).then(
      (): State => 'success',
      (error: unknown): State => {
        if (error instanceof ApiError && error.code === 'expired') return 'expired';
        if (error instanceof ApiError && (error.fields.token?.length ?? 0) > 0) return 'invalid';

        return 'error';
      },
    );
    calls.set(token, call);
  }

  return call;
}

/** The result of using a verification link. The page calls the API as soon as it loads. */
export function VerifyEmailState({ token }: { token: string | null }) {
  const { t } = useI18n();
  const [state, setState] = useState<State>(token === null ? 'invalid' : 'pending');

  useEffect(() => {
    if (token === null) return;

    let current = true;

    void verifyOnce(token).then((result) => {
      if (current) setState(result);
    });

    return () => {
      current = false;
    };
  }, [token]);

  const text: Record<State, string> = {
    pending: t('auth.verify.pending'),
    success: t('auth.verify.success'),
    invalid: t('auth.verify.invalid'),
    expired: t('auth.verify.expired'),
    error: t('auth.verify.failed'),
  };

  return (
    <div className="flex flex-col gap-5">
      <h1 className="text-2xl text-ink md:text-3xl">{t('auth.verify.title')}</h1>

      <div
        data-testid="verify-state"
        data-state={state}
        role="status"
        className="flex flex-col gap-4 text-md text-ink"
      >
        <p>{text[state]}</p>

        {state === 'success' ? (
          <Link href="/admin" className="w-fit font-semibold text-primary underline">
            {t('auth.verify.toAdmin')}
          </Link>
        ) : null}
        {state === 'invalid' || state === 'expired' ? (
          <Link href="/login" className="w-fit font-semibold text-primary underline">
            {t('auth.verify.signIn')}
          </Link>
        ) : null}
      </div>
    </div>
  );
}
