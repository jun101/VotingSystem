'use client';

import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { downloadGeneratedFile, fetchVoters, reissueCredential } from '@/lib/api/browser';
import type { Reissue } from '@/lib/api/credentials';
import { ApiError, errorText } from '@/lib/api/errors';
import type { Voter, VoterList } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import { NO_FILTERS } from '@/components/voters/voterQuery';
import { saveBlob } from './saveFile';

/** The search is sent when typing stops for this long. */
const DEBOUNCE_MS = 300;

const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

const CHECKBOX = `size-5 shrink-0 cursor-pointer accent-primary ${focusRing}`;

/**
 * The lost-slip dialog (A11): look a voter up by name, choose one, create a new code. The code
 * is shown here and nowhere else, once; it lives in this component's state only, so closing the
 * dialog (which unmounts it) erases it. It is never put in an address, a log, the storage or an
 * attribute.
 */
export function LostSlipDialog({
  election,
  onLocked,
  onClose,
}: {
  election: string;
  /** The election no longer lets codes change (409 `election_codes_locked`). */
  onLocked: () => void;
  /** Closed: the page reads the state again (the voter has a code now). */
  onClose: (changed: boolean) => void;
}) {
  const { t, tIfAny } = useI18n();
  const [text, setText] = useState('');
  const [found, setFound] = useState<VoterList | null>(null);
  const [searching, setSearching] = useState(false);
  const [searchFailed, setSearchFailed] = useState(false);
  const [chosen, setChosen] = useState<Voter | null>(null);
  // Also email the new code: off by default, offered only for a voter with an address.
  const [emailIt, setEmailIt] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<Reissue | null>(null);
  const [downloading, setDownloading] = useState(false);
  const ticket = useRef(0);

  // What was typed is searched when typing stops; an older answer is dropped.
  const timer = useRef<number | null>(null);

  useEffect(
    () => () => {
      if (timer.current !== null) window.clearTimeout(timer.current);
    },
    [],
  );

  function typed(value: string) {
    const query = value.trim();
    const mine = ++ticket.current;

    setText(value);
    setChosen(null);
    setEmailIt(false);
    setError(null);

    if (timer.current !== null) window.clearTimeout(timer.current);

    if (query === '') {
      setFound(null);
      setSearching(false);
      setSearchFailed(false);

      return;
    }

    setSearching(true);
    timer.current = window.setTimeout(() => {
      fetchVoters(election, { ...NO_FILTERS, q: query })
        .then((list) => {
          if (mine !== ticket.current) return;

          setFound(list);
          setSearchFailed(false);
          setSearching(false);
        })
        .catch(() => {
          if (mine !== ticket.current) return;

          setSearchFailed(true);
          setSearching(false);
        });
    }, DEBOUNCE_MS);
  }

  async function confirm() {
    if (!chosen || busy) return;

    setBusy(true);
    setError(null);

    try {
      // The second argument only when the code is also emailed: a voter with no address has no choice.
      setResult(
        chosen.email && emailIt
          ? await reissueCredential(chosen.id, { email: true })
          : await reissueCredential(chosen.id),
      );
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_codes_locked') {
        onLocked();

        return;
      }

      setError(errorText(failure, tIfAny));
    }

    setBusy(false);
  }

  async function download() {
    if (!result || downloading) return;

    setDownloading(true);
    setError(null);

    try {
      const file = await downloadGeneratedFile(result.file.id);

      saveBlob(file.blob, file.name);
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setError(`${t('codes.lost.downloadFailed')} ${errorText(failure, tIfAny)}`);
    }

    setDownloading(false);
  }

  const close = () => onClose(result !== null);

  return (
    <Modal
      title={t('codes.lost.title')}
      icon="qr"
      closeLabel={t('codes.lost.closeLabel')}
      onClose={close}
      busy={busy}
      testId="lost-dialog"
      closeTestId="lost-dialog-close"
    >
      {result ? (
        <div className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6">
          <p className="text-md font-bold text-ink">
            {t('codes.lost.resultTitle', { name: result.voter.full_name })}
          </p>
          <div className="flex flex-col gap-1.5 rounded-lg border border-dashed border-line-strong bg-surface-alt p-4 text-center">
            <span className="text-sm font-medium text-ink-soft">{t('codes.lost.codeLabel')}</span>
            <strong
              data-testid="lost-code"
              className="font-mono text-3xl font-extrabold tracking-widest break-all text-ink"
            >
              {result.code}
            </strong>
          </div>
          <Notice tone="warm" role="status">
            {t('codes.lost.once')}
          </Notice>
          <p className="text-base text-ink-soft">
            {result.reissue_count > 0 ? t('codes.lost.replaced') : t('codes.lost.first')}
          </p>
          {result.email === 'queued' ? (
            <p data-testid="lost-email-queued" className="text-base text-ink-soft">
              {t('codes.lost.emailQueued')}
            </p>
          ) : null}
          {error ? (
            <Notice tone="danger" role="alert" data-testid="lost-error">
              {error}
            </Notice>
          ) : null}
          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
            <Button variant="quiet" onClick={close} data-testid="lost-close">
              {t('codes.lost.close')}
            </Button>
            <Button
              loading={downloading}
              onClick={() => void download()}
              data-testid="lost-download"
            >
              <span className="inline-flex items-center gap-2">
                <Icon path={DOWNLOAD_PATH} size={18} />
                {t('codes.lost.download')}
              </span>
            </Button>
          </div>
        </div>
      ) : (
        <form
          noValidate
          aria-label={t('codes.lost.title')}
          onSubmit={(event) => {
            event.preventDefault();
            void confirm();
          }}
          className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
        >
          <p className="text-base text-ink-soft">{t('codes.lost.text')}</p>

          <Input
            type="search"
            label={t('codes.lost.search')}
            value={text}
            onChange={(event) => typed(event.target.value)}
            maxLength={100}
            autoComplete="off"
            enterKeyHint="search"
            disabled={busy}
            data-testid="lost-search"
            data-autofocus=""
          />

          <div aria-live="polite" className="flex flex-col gap-2">
            {text.trim() === '' ? (
              <p className="text-base text-ink-soft">{t('codes.lost.typeName')}</p>
            ) : searchFailed ? (
              <p role="alert" className="text-base text-danger">
                {t('codes.lost.failed')}
              </p>
            ) : searching && found === null ? (
              <p className="text-base text-ink-soft">{t('codes.lost.searching')}</p>
            ) : found !== null && found.items.length === 0 ? (
              <p data-testid="lost-no-match" className="text-base text-ink-soft">
                {t('codes.lost.noMatch')}
              </p>
            ) : null}

            {found !== null && found.items.length > 0 && text.trim() !== '' ? (
              <ul aria-label={t('codes.lost.results')} className="flex flex-col gap-2">
                {found.items.map((voter, index) => {
                  const on = chosen?.id === voter.id;

                  return (
                    <li key={voter.id}>
                      <button
                        type="button"
                        aria-pressed={on}
                        disabled={busy}
                        onClick={() => {
                          setChosen(voter);
                          setEmailIt(false);
                        }}
                        data-testid={`lost-voter-${index + 1}`}
                        className={cx(
                          'ui-control flex min-h-12 w-full items-center gap-3 rounded-lg border px-3.5 text-left',
                          focusRing,
                          on
                            ? 'border-primary bg-primary-soft'
                            : 'border-line-strong bg-surface hover:bg-primary-soft',
                        )}
                      >
                        <span className="min-w-0 flex-1 truncate text-md font-semibold text-ink">
                          {voter.full_name}
                        </span>
                        <span className="max-w-[40%] shrink-0 truncate text-sm text-ink-soft">
                          {voter.group?.name ?? t('codes.lost.noGroup')}
                        </span>
                        {on ? (
                          <span aria-hidden="true" className="text-primary">
                            <Icon name="check" size={18} />
                          </span>
                        ) : null}
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : null}

            {found !== null && found.total > found.items.length ? (
              <p className="text-sm text-ink-soft">{t('codes.lost.more')}</p>
            ) : null}
          </div>

          {chosen?.email ? (
            <label className="flex min-h-11 cursor-pointer items-start gap-3 text-md text-ink">
              <input
                type="checkbox"
                checked={emailIt}
                onChange={(event) => setEmailIt(event.target.checked)}
                disabled={busy}
                data-testid="lost-email"
                className={`${CHECKBOX} mt-0.5`}
              />
              <span className="font-medium">{t('codes.lost.email')}</span>
            </label>
          ) : null}

          {error ? (
            <Notice tone="danger" role="alert" data-testid="lost-error">
              {error}
            </Notice>
          ) : null}

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
            <Button variant="quiet" disabled={busy} onClick={close} data-testid="lost-cancel">
              {t('codes.lost.cancel')}
            </Button>
            <Button
              type="submit"
              loading={busy}
              disabled={chosen === null}
              data-testid="lost-confirm"
            >
              <span className="inline-flex items-center gap-2">
                <Icon name="qr" size={18} />
                {t('codes.lost.confirm')}
              </span>
            </Button>
          </div>
          {chosen ? (
            <p className="sr-only" role="status">
              {t('codes.lost.chosen', { name: chosen.full_name })}
            </p>
          ) : null}
        </form>
      )}
    </Modal>
  );
}
