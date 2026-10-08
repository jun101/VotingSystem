'use client';

import { useEffect, useRef, useState, type FormEvent } from 'react';
import { PasswordField } from '@/components/auth/PasswordField';
import { Button, Card, Input, Notice, Pill } from '@/components/ui';
import {
  confirmTwoFactor,
  disableTwoFactor,
  renewRecoveryCodes,
  startTwoFactorSetup,
} from '@/lib/api/browser';
import type { TwoFactorSetup, TwoFactorState } from '@/lib/api/user';
import { useI18n } from '@/lib/i18n/client';
import { PasswordDialog } from './PasswordDialog';
import { QrCode } from './QrCode';
import { RecoveryCodes } from './RecoveryCodes';
import { useGuardedCall } from './useGuardedCall';

type Phase = 'idle' | 'password' | 'scan' | 'codes';

/** The secret in groups of four, to type into an application that cannot scan. */
function inGroups(secret: string): string {
  return secret.match(/.{1,4}/g)?.join(' ') ?? secret;
}

/**
 * The "Sécurité" card of the account page: the state of two-factor authentication and the way
 * to turn it on (password, QR code and first code, recovery codes), off, or to renew the
 * recovery codes. The codes live in this component's memory only until the person finishes.
 */
export function SecurityCard({ initial }: { initial: TwoFactorState }) {
  const { t } = useI18n();
  const [state, setState] = useState(initial);
  const [phase, setPhase] = useState<Phase>('idle');
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [codes, setCodes] = useState<string[]>([]);
  const [dialog, setDialog] = useState<'disable' | 'renew' | null>(null);
  const card = useRef<HTMLElement>(null);

  function backToCard() {
    window.setTimeout(() => card.current?.focus(), 0);
  }

  const count = state.recovery_codes_left ?? 0;

  return (
    <Card
      ref={card}
      tabIndex={-1}
      title={t('account.security.title')}
      data-testid="security-card"
      className="outline-none"
    >
      <div className="flex flex-col gap-5">
        <p className="text-base text-ink-soft">{t('account.security.lede')}</p>

        <div className="flex flex-wrap items-center gap-3">
          <span className="text-base font-semibold text-ink">{t('account.security.label')}</span>
          <Pill tone={state.enabled ? 'teal' : 'neutral'} data-testid="two-factor-status">
            {t(state.enabled ? 'account.security.on' : 'account.security.off')}
          </Pill>
          {state.enabled ? (
            <span data-testid="recovery-codes-left" className="text-base text-ink-soft">
              {t(count === 1 ? 'account.security.codesLeftOne' : 'account.security.codesLeftMany', {
                count,
              })}
            </span>
          ) : null}
        </div>

        {phase === 'idle' ? (
          <div className="flex flex-wrap gap-3">
            {state.enabled ? (
              <>
                <Button
                  variant="secondary"
                  onClick={() => setDialog('renew')}
                  data-testid="two-factor-codes-open"
                >
                  {t('account.security.renew')}
                </Button>
                <Button
                  variant="danger"
                  onClick={() => setDialog('disable')}
                  data-testid="two-factor-disable-open"
                >
                  {t('account.security.disable')}
                </Button>
              </>
            ) : (
              <Button
                variant="primary"
                onClick={() => setPhase('password')}
                data-testid="two-factor-setup-open"
              >
                {t('account.security.enable')}
              </Button>
            )}
          </div>
        ) : null}

        {phase === 'password' ? (
          <PasswordStep
            onCancel={() => {
              setPhase('idle');
              backToCard();
            }}
            onStarted={(started) => {
              setSetup(started);
              setPhase('scan');
            }}
          />
        ) : null}

        {phase === 'scan' && setup ? (
          <ScanStep
            setup={setup}
            onCancel={() => {
              setSetup(null);
              setPhase('idle');
              backToCard();
            }}
            onConfirmed={(recovery) => {
              setCodes(recovery);
              setSetup(null);
              setState({
                enabled: true,
                setup_started: false,
                recovery_codes_left: recovery.length,
              });
              setPhase('codes');
            }}
          />
        ) : null}

        {phase === 'codes' ? (
          <RecoveryCodes
            codes={codes}
            onDone={() => {
              // The codes are not kept anywhere once the person has them.
              setCodes([]);
              setPhase('idle');
              backToCard();
            }}
          />
        ) : null}
      </div>

      {dialog === 'disable' ? (
        <PasswordDialog
          title={t('account.disable.title')}
          text={t('account.disable.text')}
          confirmLabel={t('account.disable.confirm')}
          action={disableTwoFactor}
          onDone={() => {
            setState({ enabled: false, setup_started: false, recovery_codes_left: null });
            setDialog(null);
            backToCard();
          }}
          onCancel={() => setDialog(null)}
          testIds={{
            dialog: 'two-factor-disable-dialog',
            confirm: 'two-factor-disable-confirm',
            cancel: 'two-factor-disable-cancel',
          }}
        />
      ) : null}

      {dialog === 'renew' ? (
        <PasswordDialog
          title={t('account.renew.title')}
          text={t('account.renew.text')}
          confirmLabel={t('account.renew.confirm')}
          action={async (password) => {
            const fresh = await renewRecoveryCodes(password);

            setCodes(fresh);
            setState({ enabled: true, setup_started: false, recovery_codes_left: fresh.length });
          }}
          onDone={() => {
            setDialog(null);
            setPhase('codes');
          }}
          onCancel={() => setDialog(null)}
          testIds={{
            dialog: 'two-factor-codes-dialog',
            confirm: 'two-factor-codes-confirm',
            cancel: 'two-factor-codes-cancel',
          }}
        />
      ) : null}
    </Card>
  );
}

/** Step 1: the password, asked again before a secret is issued. */
function PasswordStep({
  onStarted,
  onCancel,
}: {
  onStarted: (setup: TwoFactorSetup) => void;
  onCancel: () => void;
}) {
  const { t } = useI18n();
  const { busy, fieldError, problem, round, run } = useGuardedCall('password');
  const field = useRef<HTMLInputElement>(null);
  const alert = useRef<HTMLDivElement>(null);

  useEffect(() => field.current?.focus(), []);

  // After a failure the focus goes to where the error is read.
  useEffect(() => {
    if (round === 0) return;

    (fieldError ? field.current : alert.current)?.focus();
  }, [round, fieldError]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const password = String(new FormData(event.currentTarget).get('password') ?? '');

    await run(async () => onStarted(await startTwoFactorSetup(password)));
  }

  return (
    <form
      method="post"
      noValidate
      onSubmit={submit}
      aria-labelledby="setup-password-title"
      className="flex flex-col gap-4 rounded-md border border-primary-line bg-primary-soft p-4"
    >
      <div className="flex flex-col gap-1">
        <h3 id="setup-password-title" className="text-md font-bold text-ink">
          {t('account.setup.passwordTitle')}
        </h3>
        <p className="text-base text-ink-soft">{t('account.setup.passwordHelp')}</p>
      </div>

      {problem ? (
        <div ref={alert} tabIndex={-1}>
          <Notice tone="danger" role="alert" data-testid="two-factor-error">
            {problem}
          </Notice>
        </div>
      ) : null}

      <PasswordField
        ref={field}
        label={t('account.setup.password')}
        name="password"
        autoComplete="current-password"
        data-testid="two-factor-password"
        toggleTestId="two-factor-password-toggle"
        error={fieldError ?? undefined}
        errorTestId="two-factor-password-error"
      />

      <div className="flex flex-wrap gap-3">
        <Button
          type="submit"
          variant="primary"
          loading={busy}
          data-testid="two-factor-setup-submit"
        >
          {t('account.setup.continue')}
        </Button>
        <Button variant="secondary" onClick={onCancel} data-testid="two-factor-setup-cancel">
          {t('account.setup.cancel')}
        </Button>
      </div>
    </form>
  );
}

/** Step 2: the QR code (and the secret for manual entry) and the first code. */
function ScanStep({
  setup,
  onConfirmed,
  onCancel,
}: {
  setup: TwoFactorSetup;
  onConfirmed: (codes: string[]) => void;
  onCancel: () => void;
}) {
  const { t } = useI18n();
  const { busy, fieldError, problem, round, run } = useGuardedCall('code');
  const field = useRef<HTMLInputElement>(null);
  const alert = useRef<HTMLDivElement>(null);

  useEffect(() => field.current?.focus(), []);

  useEffect(() => {
    if (round === 0) return;

    (fieldError ? field.current : alert.current)?.focus();
  }, [round, fieldError]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const code = String(new FormData(event.currentTarget).get('code') ?? '');

    await run(async () => onConfirmed(await confirmTwoFactor(code)));
  }

  return (
    <section
      aria-labelledby="setup-scan-title"
      className="flex flex-col gap-4 rounded-md border border-primary-line bg-primary-soft p-4"
    >
      <h3 id="setup-scan-title" className="text-md font-bold text-ink">
        {t('account.setup.scanTitle')}
      </h3>

      <div className="flex flex-col items-start gap-4 md:flex-row">
        <QrCode value={setup.otpauth_url} label={t('account.setup.qrLabel')} />
        <div className="flex min-w-0 flex-col gap-3">
          <p className="text-base text-ink-soft">{t('account.setup.scanText')}</p>
          <p className="text-base text-ink-soft">{t('account.setup.manual')}</p>
          <code
            data-testid="two-factor-secret"
            className="w-fit max-w-full rounded border border-line bg-surface px-3 py-2 font-mono text-md font-semibold tracking-wider break-words text-ink"
          >
            {inGroups(setup.secret)}
          </code>
        </div>
      </div>

      <form method="post" noValidate onSubmit={submit} className="flex flex-col gap-4">
        {problem ? (
          <div ref={alert} tabIndex={-1}>
            <Notice tone="danger" role="alert" data-testid="two-factor-error">
              {problem}
            </Notice>
          </div>
        ) : null}

        <Input
          ref={field}
          label={t('account.setup.code')}
          help={t('account.setup.codeHelp')}
          name="code"
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          spellCheck={false}
          data-testid="two-factor-code"
          error={fieldError ?? undefined}
          errorTestId="two-factor-code-error"
        />

        <div className="flex flex-wrap gap-3">
          <Button type="submit" variant="primary" loading={busy} data-testid="two-factor-confirm">
            {t('account.setup.confirm')}
          </Button>
          <Button variant="secondary" onClick={onCancel} data-testid="two-factor-scan-cancel">
            {t('account.setup.cancel')}
          </Button>
        </div>
      </form>
    </section>
  );
}
