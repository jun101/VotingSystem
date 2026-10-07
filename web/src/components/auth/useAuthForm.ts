'use client';

import { useEffect, useRef, useState } from 'react';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * What every form of the sign-in pages does around its call to the API:
 *
 * - while the call runs, `busy` (the submit button is disabled and announced as busy);
 * - an error that belongs to a field is shown next to it, the field is marked invalid, and
 *   the first one in the form gets the focus;
 * - any other error is shown in one alert for the form.
 *
 * `shown` names the fields the form has. An error on another field (the token of a link, a
 * field the page does not show) goes in the alert. `handle` can take an error over (return
 * true) before anything is shown.
 */
export function useAuthForm(shown: readonly string[]) {
  const { tIfAny } = useI18n();
  const form = useRef<HTMLFormElement>(null);
  const [busy, setBusy] = useState(false);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [round, setRound] = useState(0);

  // After the errors are on the screen, the first invalid field gets the focus.
  useEffect(() => {
    if (round > 0) form.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  }, [round]);

  /**
   * Runs the call. On success the form stays busy (the page is about to change); call
   * `idle()` if it stays. Resolves true on success.
   */
  async function run(
    call: () => Promise<unknown>,
    handle?: (error: ApiError) => boolean,
  ): Promise<boolean> {
    setBusy(true);
    setFields({});
    setFormError(null);

    try {
      await call();

      return true;
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (handle?.(error)) {
        setBusy(false);

        return false;
      }

      const own: Record<string, string> = {};
      let foreign = false;

      for (const [field, codes] of Object.entries(error.fields)) {
        if (shown.includes(field) && codes[0]) own[field] = fieldText(field, codes[0], tIfAny);
        else foreign = true;
      }

      setFields(own);
      setFormError(
        error.code === 'validation_failed' && Object.keys(own).length > 0 && !foreign
          ? null
          : errorText(error, tIfAny),
      );
      setRound((n) => n + 1);
      setBusy(false);

      return false;
    }
  }

  return { form, busy, fields, formError, run, idle: () => setBusy(false) };
}
