'use client';

import { useEffect, useRef, useState } from 'react';
import { useDeployment } from '@/components/admin/Deployment';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Button, Modal, Notice, Select } from '@/components/ui';
import { previewCredentialRun, startCredentialRun } from '@/lib/api/browser';
import type { CredentialPreview, CredentialRun, CredentialState } from '@/lib/api/credentials';
import { ApiError, errorText } from '@/lib/api/errors';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import {
  canConfirm,
  emailForced,
  emailOn,
  noEmailMore,
  previewBodyOf,
  runBodyFor,
  scopeOptions,
  type SendDraft,
  type SendFile,
} from './sendRules';

const CHECKBOX = `size-5 shrink-0 cursor-pointer accent-primary ${focusRing}`;

/** One number of the preview: a tile with the figure and what it counts. */
function Figure({
  testId,
  value,
  label,
  tone,
}: {
  testId: string;
  value: number | null;
  label: string;
  tone: string;
}) {
  return (
    <div className={`flex flex-col-reverse gap-0.5 rounded-md p-3 ${tone}`}>
      <dt className="text-sm font-medium">{label}</dt>
      <dd data-testid={testId} className="font-display text-[28px] leading-9 font-extrabold">
        {value === null ? '…' : value}
      </dd>
    </div>
  );
}

/**
 * The dialog that generates the codes and sends them (A11, slice 11c): the scope, the email
 * channel, the self-distribution file, and the numbers the server would act on, read before
 * anything is done. The confirm button sends the count of emails the person saw; if it is no
 * longer the right one the numbers are read again and nothing was done. No code is ever shown
 * here: emails, files and slips are the only channels.
 */
export function SendDialog({
  election,
  state,
  initial,
  onStarted,
  onLocked,
  onClose,
}: {
  election: string;
  /** The totals and the groups of the page: the scopes are offered from them. */
  state: CredentialState;
  initial: SendDraft;
  /** A run was accepted: the page watches its files; the dialog is closed after this. */
  onStarted: (run: CredentialRun) => void;
  /** The election no longer lets codes change (409 `election_codes_locked`). */
  onLocked: () => void;
  onClose: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  // School mode sends no email: the channel and the "failed email" scope are not offered.
  const { school } = useDeployment();
  const [draft, setDraft] = useState<SendDraft>(school ? { ...initial, email: false } : initial);
  // What was read, and for which scope and attempt: a read of another scope is not shown.
  const [read, setRead] = useState<{
    key: string;
    preview: CredentialPreview | null;
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [changed, setChanged] = useState(false);
  const [attempt, setAttempt] = useState(0);
  // The newest read: an older answer is dropped.
  const ticket = useRef(0);

  const readKey = `${draft.scope}#${attempt}`;
  const current = read !== null && read.key === readKey ? read : null;
  const preview = current?.preview ?? null;
  const readFailed = current !== null && current.preview === null;

  // The numbers are read when the dialog opens, on each change of scope and when asked again.
  useEffect(() => {
    const mine = ++ticket.current;
    const key = `${draft.scope}#${attempt}`;

    previewCredentialRun(election, previewBodyOf(draft.scope))
      .then((answer) => {
        if (mine === ticket.current) setRead({ key, preview: answer });
      })
      .catch((caught: unknown) => {
        if (mine !== ticket.current) return;

        if (caught instanceof ApiError && caught.code === 'election_codes_locked') {
          onLocked();

          return;
        }

        setRead({ key, preview: null });
      });
    // `onLocked` is the page's own; the read follows the scope and the retry counter only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [election, draft.scope, attempt]);

  function patch(next: Partial<SendDraft>) {
    setDraft((now) => ({ ...now, ...next }));
    setError(null);
    setChanged(false);
  }

  async function confirm() {
    if (preview === null || !canConfirm(draft, preview, busy)) return;

    setBusy(true);
    setError(null);
    setChanged(false);

    try {
      const run = await startCredentialRun(election, runBodyFor(draft, preview));

      onStarted(run);
      onClose();

      return;
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_codes_locked') {
        onLocked();

        return;
      }

      if (failure.code === 'email_count_changed') {
        // Nothing was done: the numbers are read again and the person confirms the new ones.
        setChanged(true);
        setAttempt((n) => n + 1);
      } else {
        setError(errorText(failure, tIfAny));

        if (failure.code === 'nothing_to_generate') setAttempt((n) => n + 1);
      }
    }

    setBusy(false);
  }

  const sending = emailOn(draft);
  const ready = canConfirm(draft, preview, busy);
  const more = preview ? noEmailMore(preview) : 0;

  return (
    <Modal
      title={t('codes.send.title')}
      icon="mail"
      closeLabel={t('codes.send.closeLabel')}
      onClose={onClose}
      busy={busy}
      testId="send-dialog"
      closeTestId="send-dialog-close"
    >
      <form
        noValidate
        aria-label={t('codes.send.title')}
        onSubmit={(event) => {
          event.preventDefault();
          void confirm();
        }}
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <p className="text-base text-ink-soft">{t('codes.send.text')}</p>

        <Select
          label={t('codes.send.scopeLabel')}
          value={draft.scope}
          onChange={(event) => {
            const scope = event.target.value;

            patch({ scope, ...(scope === 'failed_email' ? { email: true } : {}) });
          }}
          disabled={busy}
          data-testid="send-scope"
          data-autofocus=""
        >
          {scopeOptions(state)
            .filter((option) => !(school && option.value === 'failed_email'))
            .map((option) => (
              <option key={option.value} value={option.value}>
                {'name' in option
                  ? t('codes.send.scopeGroup', { name: option.name })
                  : t(`codes.send.scope.${option.value}`)}
              </option>
            ))}
        </Select>

        {school ? null : (
          <label className="flex min-h-11 cursor-pointer items-start gap-3 text-md text-ink">
            <input
              type="checkbox"
              checked={sending}
              onChange={(event) => patch({ email: event.target.checked })}
              disabled={busy || emailForced(draft)}
              data-testid="send-email"
              className={`${CHECKBOX} mt-0.5`}
            />
            <span className="flex min-w-0 flex-col">
              <span className="font-medium">{t('codes.send.email')}</span>
              <span className="text-sm text-ink-soft">{t('codes.send.emailHelp')}</span>
            </span>
          </label>
        )}

        <Select
          label={t('codes.send.file')}
          value={draft.file}
          onChange={(event) => patch({ file: event.target.value as SendFile })}
          disabled={busy}
          data-testid="send-file"
        >
          <option value="">{t('codes.send.fileNone')}</option>
          <option value="xlsx">{t('codes.send.fileXlsx')}</option>
          <option value="csv">{t('codes.send.fileCsv')}</option>
        </Select>

        <section aria-label={t('codes.send.numbers')} className="flex flex-col gap-3">
          {readFailed ? (
            <Notice tone="danger" role="alert" data-testid="send-read-failed">
              <span className="flex flex-wrap items-center gap-2">
                {t('codes.send.readFailed')}
                <button
                  type="button"
                  onClick={() => setAttempt((n) => n + 1)}
                  data-testid="send-read-retry"
                  className={`ui-control min-h-11 rounded-full px-3 font-bold text-primary-hover ${focusRing}`}
                >
                  {t('codes.send.retry')}
                </button>
              </span>
            </Notice>
          ) : null}

          <dl className="grid grid-cols-2 gap-3 text-left">
            <Figure
              testId="send-count-generate"
              value={preview?.to_generate ?? null}
              label={t('codes.send.countGenerate')}
              tone="bg-canvas text-ink"
            />
            {school ? null : (
              <Figure
                testId="send-count-email"
                value={preview?.with_email ?? null}
                label={t('codes.send.countEmail')}
                tone="bg-teal-soft text-teal-ink"
              />
            )}
            {school ? null : (
              <Figure
                testId="send-count-no-email"
                value={preview?.without_email ?? null}
                label={t('codes.send.countNoEmail')}
                tone="bg-warm-softer text-warm-ink"
              />
            )}
            <Figure
              testId="send-count-skipped"
              value={preview?.skipped ?? null}
              label={t('codes.send.countSkipped')}
              tone="bg-canvas text-ink"
            />
          </dl>

          {preview && preview.to_generate === 0 ? (
            <p className="text-base text-ink-soft" data-testid="send-nothing">
              {t('codes.send.nothing')}
            </p>
          ) : null}

          {preview && sending && preview.to_generate > 0 && preview.with_email === 0 ? (
            <p className="text-base text-ink-soft" data-testid="send-nobody">
              {t('codes.send.nobody')}
            </p>
          ) : null}

          {preview && sending && preview.without_email > 0 ? (
            <div data-testid="send-no-email-box" className="flex flex-col gap-2">
              <h4 className="text-md font-bold text-ink">
                {t(`codes.send.noEmailTitle.${pluralForm(locale, preview.without_email)}`, {
                  count: preview.without_email,
                })}
              </h4>
              <ul
                data-testid="send-no-email-list"
                className="flex flex-col gap-1 text-base text-ink"
              >
                {preview.voters_without_email.map((voter) => (
                  <li key={voter.id} className="flex min-w-0 items-center gap-2">
                    <Icon name="user" size={16} />
                    <span className="min-w-0 truncate">{voter.full_name}</span>
                    {voter.group ? (
                      <span className="min-w-0 shrink truncate text-sm text-ink-soft">
                        {voter.group.name}
                      </span>
                    ) : null}
                  </li>
                ))}
              </ul>
              {more > 0 ? (
                <p className="text-sm text-ink-soft" data-testid="send-no-email-more">
                  {t('codes.send.noEmailMore', { count: more })}
                </p>
              ) : null}
              <p className="text-sm text-ink-soft">{t('codes.send.noEmailPrint')}</p>
            </div>
          ) : null}
        </section>

        {changed ? (
          <Notice tone="info" role="status" data-testid="send-changed">
            {t('codes.send.changed')}
          </Notice>
        ) : null}

        {error ? (
          <Notice tone="danger" role="alert" data-testid="send-error">
            {error}
          </Notice>
        ) : null}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button variant="quiet" disabled={busy} onClick={onClose} data-testid="send-cancel">
            {t('codes.send.cancel')}
          </Button>
          <Button type="submit" loading={busy} disabled={!ready} data-testid="send-confirm">
            <span className="inline-flex items-center gap-2">
              <Icon name={sending ? 'mail' : 'qr'} size={18} />
              {sending && preview && preview.with_email > 0
                ? t(`codes.send.confirmEmail.${pluralForm(locale, preview.with_email)}`, {
                    count: preview.with_email,
                  })
                : t('codes.send.confirm')}
            </span>
          </Button>
        </div>
      </form>
    </Modal>
  );
}
