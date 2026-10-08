'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { ApiError, fieldText, waitText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * What the calls of the account page do around the API: while it runs, `busy`; an error that
 * belongs to one of the fields (`password`, `code`, `recovery_code`) is a field error, any
 * other one is `problem` (a 429 tells how many minutes to wait). `fieldError` is the text of
 * the first field error, `fieldErrors` the text of each, and `fieldCodes` the codes the API gave,
 * for a page that shows one under another field. `round` goes up at each failure, so the page
 * can move the focus to the first error.
 */
export function useGuardedCall(fields: string | readonly string[]) {
  const { tIfAny } = useI18n();
  const router = useRouter();
  const names = typeof fields === 'string' ? [fields] : fields;
  const [busy, setBusy] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [fieldCodes, setFieldCodes] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [round, setRound] = useState(0);

  /** Resolves true when the call succeeded. */
  async function run(call: () => Promise<unknown>): Promise<boolean> {
    setBusy(true);
    setFieldErrors({});
    setFieldCodes({});
    setProblem(null);

    try {
      await call();
      setBusy(false);

      return true;
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      // Too many wrong passwords end the session (401): back to the sign-in page.
      if (error.status === 401) {
        router.push('/login');
        router.refresh();
      }

      const own = Object.keys(error.fields).filter((name) => names.includes(name));
      const foreign = Object.keys(error.fields).some((name) => !names.includes(name));

      if (own.length > 0 && !foreign) {
        const texts: Record<string, string> = {};
        const codes: Record<string, string> = {};

        for (const name of own) {
          const code = error.fields[name]![0];

          if (code === undefined) continue;

          codes[name] = code;
          texts[name] = fieldText(`twofactor.${name}`, code, tIfAny);
        }

        setFieldCodes(codes);
        setFieldErrors(texts);
      } else {
        setProblem(waitText(error, tIfAny));
      }

      setRound((n) => n + 1);
      setBusy(false);

      return false;
    }
  }

  function reset() {
    setFieldErrors({});
    setFieldCodes({});
    setProblem(null);
  }

  const first = names.map((name) => fieldErrors[name]).find((text) => text !== undefined);

  return { busy, fieldError: first ?? null, fieldErrors, fieldCodes, problem, round, run, reset };
}
