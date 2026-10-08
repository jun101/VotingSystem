'use client';

import { useEffect, useRef, useState } from 'react';
import { Input } from '@/components/ui';
import type { SecondFactor } from '@/lib/api/browser';
import { fieldText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

type Mode = 'code' | 'recovery';

/**
 * The state of a second-factor field: the 6-digit code, or (after the link) one recovery code.
 * `factor()` is what the API client sends; swapping the field empties it.
 */
export function useSecondFactor() {
  const [mode, setMode] = useState<Mode>('code');
  const [value, setValue] = useState('');

  return {
    mode,
    value,
    setValue,
    reset: () => {
      setMode('code');
      setValue('');
    },
    swap: () => {
      setMode((current) => (current === 'code' ? 'recovery' : 'code'));
      setValue('');
    },
    factor: (): SecondFactor => (mode === 'code' ? { code: value } : { recovery_code: value }),
  };
}

/**
 * Where the error of the second factor is read: the API has one "missing" answer
 * (`code: required`), which is told under the recovery field when that one is shown.
 */
export function useSecondFactorError(
  mode: Mode,
  fieldErrors: Record<string, string>,
  fieldCodes: Record<string, string>,
): string | undefined {
  const { tIfAny } = useI18n();

  if (mode === 'code') return fieldErrors.code;

  return (
    fieldErrors.recovery_code ??
    (fieldCodes.code === 'required'
      ? fieldText('twofactor.recovery_code', 'required', tIfAny)
      : undefined)
  );
}

/**
 * The code field and the link that swaps it for the recovery code field. Focus goes to the field
 * shown whenever it is swapped, and `focusRound` lets the page put it there after a failure.
 */
export function SecondFactorField({
  mode,
  value,
  onChange,
  onSwap,
  error,
  labels,
  testIds,
  focusRound = 0,
}: {
  mode: Mode;
  value: string;
  onChange: (value: string) => void;
  onSwap: () => void;
  error?: string;
  labels: { code: string; codeHelp: string; recovery: string; recoveryHelp: string };
  testIds: {
    code: string;
    codeError: string;
    recovery: string;
    recoveryError: string;
    toggle: string;
  };
  /** Goes up when the page wants the field to take the focus (a failure told under it). */
  focusRound?: number;
}) {
  const { t } = useI18n();
  const own = useRef<HTMLInputElement>(null);
  const swapped = useRef(false);

  // The field shown after a swap takes the focus (not the first time: the dialog decides there).
  useEffect(() => {
    if (swapped.current) own.current?.focus();
  }, [mode]);

  useEffect(() => {
    if (focusRound > 0) own.current?.focus();
  }, [focusRound]);

  return (
    <div className="flex flex-col gap-1">
      {mode === 'code' ? (
        <Input
          key="code"
          ref={own}
          label={labels.code}
          help={labels.codeHelp}
          name="code"
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          spellCheck={false}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          data-testid={testIds.code}
          error={error}
          errorTestId={testIds.codeError}
        />
      ) : (
        <Input
          key="recovery"
          ref={own}
          label={labels.recovery}
          help={labels.recoveryHelp}
          name="recovery_code"
          type="text"
          autoComplete="off"
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          data-testid={testIds.recovery}
          error={error}
          errorTestId={testIds.recoveryError}
        />
      )}
      <button
        type="button"
        data-testid={testIds.toggle}
        onClick={() => {
          swapped.current = true;
          onSwap();
        }}
        className="ui-control min-h-11 self-start text-start text-base font-semibold text-primary underline"
      >
        {t(mode === 'code' ? 'account.secondFactor.useRecovery' : 'account.secondFactor.useCode')}
      </button>
    </div>
  );
}
