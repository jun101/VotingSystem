'use client';

import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { PasswordField } from '@/components/auth/PasswordField';
import { ConfirmDialog } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';
import { useGuardedCall } from './useGuardedCall';

/**
 * A confirmation that asks for the password again (turning two-factor off, renewing the recovery
 * codes). A wrong password is told under the field and the dialog stays open; success hands over
 * to the page, which closes it.
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
  /** The call to make with the password. */
  action: (password: string) => Promise<unknown>;
  onDone: () => void;
  onCancel: () => void;
  testIds: { dialog: string; confirm: string; cancel: string };
}) {
  const { t } = useI18n();
  const { busy, fieldError, problem, round, run } = useGuardedCall('password');
  const [password, setPassword] = useState('');
  const field = useRef<HTMLInputElement>(null);
  const textId = useId();
  // Set at once, not at the next render: a double Enter sends one request.
  const running = useRef(false);

  // A failed attempt puts the focus back on the field, where the error is read.
  useEffect(() => {
    if (round > 0) field.current?.focus();
  }, [round]);

  async function confirm() {
    if (running.current || busy) return;

    running.current = true;

    try {
      if (await run(() => action(password))) onDone();
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
          error={fieldError ?? undefined}
          errorTestId="two-factor-password-error"
        />
      </form>
    </ConfirmDialog>
  );
}
