'use client';

import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { PasswordField } from '@/components/auth/PasswordField';
import { ConfirmDialog } from '@/components/ui';
import type { SecondFactor } from '@/lib/api/browser';
import { useI18n } from '@/lib/i18n/client';
import { SecondFactorField, useSecondFactor, useSecondFactorError } from './SecondFactorField';
import { useGuardedCall } from './useGuardedCall';

/**
 * A confirmation that asks for the password again and a current second factor (turning two-factor
 * off, renewing the recovery codes): the 6-digit code, or a recovery code through the link. A
 * wrong password or code is told under its field and the dialog stays open; success hands over to
 * the page, which closes it.
 */
export function PasswordDialog({
  title,
  text,
  confirmLabel,
  action,
  onDone,
  onCancel,
  testIds,
}: {
  title: string;
  text: string;
  confirmLabel: string;
  /** The call to make with the password and the second factor. */
  action: (password: string, factor: SecondFactor) => Promise<unknown>;
  onDone: () => void;
  onCancel: () => void;
  testIds: { dialog: string; confirm: string; cancel: string };
}) {
  const { t } = useI18n();
  const { busy, fieldErrors, fieldCodes, problem, round, run } = useGuardedCall([
    'password',
    'code',
    'recovery_code',
  ]);
  const second = useSecondFactor();
  const secondError = useSecondFactorError(second.mode, fieldErrors, fieldCodes);
  const [password, setPassword] = useState('');
  const field = useRef<HTMLInputElement>(null);
  const textId = useId();
  // Set at once, not at the next render: a double Enter sends one request.
  const running = useRef(false);

  // A failed attempt puts the focus on the first field with an error (the password when none is
  // told under a field), where the error is read.
  const secondFailed = Boolean(fieldErrors.code || fieldErrors.recovery_code);
  const passwordFailed = Boolean(fieldErrors.password) || !secondFailed;

  useEffect(() => {
    if (round > 0 && passwordFailed) field.current?.focus();
  }, [round, passwordFailed]);

  async function confirm() {
    if (running.current || busy) return;

    running.current = true;

    try {
      if (await run(() => action(password, second.factor()))) onDone();
    } finally {
      running.current = false;
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void confirm();
  }

  return (
    <ConfirmDialog
      title={title}
      confirmLabel={confirmLabel}
      cancelLabel={t('account.dialog.cancel')}
      busy={busy}
      error={problem}
      describedBy={textId}
      onConfirm={() => void confirm()}
      onCancel={onCancel}
      testIds={testIds}
    >
      <form method="post" noValidate onSubmit={submit} className="flex flex-col gap-4">
        <p id={textId}>{text}</p>
        <PasswordField
          ref={field}
          label={t('account.dialog.password')}
          name="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          data-testid="two-factor-password"
          toggleTestId="two-factor-password-toggle"
          error={fieldErrors.password}
          errorTestId="two-factor-password-error"
        />
        <SecondFactorField
          mode={second.mode}
          value={second.value}
          onChange={second.setValue}
          onSwap={second.swap}
          error={secondError}
          focusRound={passwordFailed ? 0 : round}
          labels={{
            code: t('account.secondFactor.code'),
            codeHelp: t('account.secondFactor.codeHelp'),
            recovery: t('account.secondFactor.recoveryCode'),
            recoveryHelp: t('account.secondFactor.recoveryHelp'),
          }}
          testIds={{
            code: 'two-factor-code',
            codeError: 'two-factor-code-error',
            recovery: 'two-factor-recovery-code',
            recoveryError: 'two-factor-recovery-code-error',
            toggle: 'two-factor-recovery-toggle',
          }}
        />
      </form>
    </ConfirmDialog>
  );
}
