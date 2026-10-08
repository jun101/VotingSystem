'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * What the calls of the account page do around the API: while it runs, `busy`; an error that
 * belongs to the field (`password` or `code`) is `fieldError`, any other one is `problem`.
 * `round` goes up at each failure, so the page can move the focus to the first error.
 */
export function useGuardedCall(field: 'password' | 'code') {
  const { tIfAny } = useI18n();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [round, setRound] = useState(0);

  /** Resolves true when the call succeeded. */
  async function run(call: () => Promise<unknown>): Promise<boolean> {
    setBusy(true);
    setFieldError(null);
    setProblem(null);

    try {
      await call();
      setBusy(false);

      return true;
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const code = error.fields[field]?.[0];

      // Too many wrong passwords end the session (401): back to the sign-in page.
      if (error.status === 401) {
        router.push('/login');
        router.refresh();
      }

      const foreign = Object.keys(error.fields).some((name) => name !== field);

      if (code && !foreign) {
        setFieldError(fieldText(`twofactor.${field}`, code, tIfAny));
      } else {
        setProblem(errorText(error, tIfAny));
      }

      setRound((n) => n + 1);
      setBusy(false);

      return false;
    }
  }

  function reset() {
    setFieldError(null);
    setProblem(null);
  }

  return { busy, fieldError, problem, round, run, reset };
}
