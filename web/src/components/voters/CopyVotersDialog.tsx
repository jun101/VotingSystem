'use client';

import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Button, Input, Modal, Notice, Select } from '@/components/ui';
import { copyVoters, fetchElectionsPage, fetchGroups } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError } from '@/lib/api/errors';
import type { VoterCopyResult } from '@/lib/api/voters';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import {
  EMPTY_COPY,
  copyBody,
  copyCount,
  copyErrorKey,
  copyReady,
  toggleCopyGroup,
  type CopyDraft,
  type CopySource,
} from './copyRules';

/** Elections are read 100 a page; no institution has more than a few pages of them. */
const MAX_PAGES = 10;

/** The group names are 100 characters at most. */
const NAME_MAX = 100;

const RADIO = `size-5 shrink-0 cursor-pointer accent-primary ${focusRing}`;

/**
 * The dialog that loads voters from another election of the institution (A08, slice 09b): the
 * source, then every voter or some groups (with the ungrouped voters and a new name for each
 * group), then the result. The server judges the body and the limits; a refusal is shown in
 * words and the dialog stays open. Closing after a result asks the page to read the list again.
 */
export function CopyVotersDialog({
  election,
  onDone,
  onLocked,
  onCancel,
}: {
  /** The target election's UUID. */
  election: string;
  /** The dialog is closed after a copy: the page reads the voters and the groups again. */
  onDone: () => void;
  /** The election no longer lets voters change (409 `election_voters_locked`). */
  onLocked: () => void;
  onCancel: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const [draft, setDraft] = useState<CopyDraft>(EMPTY_COPY);
  const [sources, setSources] = useState<Election[] | null>(null);
  const [sourcesFailed, setSourcesFailed] = useState(false);
  const [source, setSource] = useState<CopySource | null>(null);
  const [sourceFailed, setSourceFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<VoterCopyResult | null>(null);
  // The newest source asked for: an older answer is dropped.
  const ticket = useRef(0);

  // The other elections of the institution.
  useEffect(() => {
    let alive = true;

    (async () => {
      const found: Election[] = [];

      for (let page = 1; page <= MAX_PAGES; page++) {
        const read = await fetchElectionsPage({}, page);

        found.push(...read.items);

        if (read.items.length === 0 || found.length >= read.total) break;
      }

      if (alive) setSources(found.filter((one) => one.id !== election));
    })().catch(() => {
      if (alive) setSourcesFailed(true);
    });

    return () => {
      alive = false;
    };
  }, [election]);

  function choose(id: string) {
    const mine = ++ticket.current;

    setDraft({ ...EMPTY_COPY, source: id });
    setSource(null);
    setSourceFailed(false);
    setError(null);

    if (id === '') return;

    fetchGroups(id)
      .then((read) => {
        if (mine === ticket.current) setSource({ groups: read.items, ungrouped: read.ungrouped });
      })
      .catch(() => {
        if (mine === ticket.current) setSourceFailed(true);
      });
  }

  function patch(next: Partial<CopyDraft>) {
    setDraft((now) => ({ ...now, ...next }));
    setError(null);
  }

  async function submit() {
    if (busy || !copyReady(draft)) return;

    setBusy(true);
    setError(null);

    try {
      setResult(await copyVoters(election, copyBody(draft)));
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_voters_locked') {
        onLocked();

        return;
      }

      const key = copyErrorKey(failure);

      setError(tIfAny(key) ?? tIfAny(`errors.${failure.code}`) ?? t('voters.copy.error'));
    }

    setBusy(false);
  }

  const ready = source !== null && copyReady(draft);
  const count = source === null ? null : copyCount(draft, source);
  const close = result ? onDone : onCancel;

  return (
    <Modal
      title={t('voters.copy.title')}
      icon="people"
      closeLabel={t('voters.copy.close')}
      onClose={close}
      busy={busy}
      testId="copy-dialog"
      closeTestId="copy-dialog-close"
    >
      {result ? (
        <div data-testid="copy-result" className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6">
          <p className="flex items-center gap-2 text-md font-bold text-status-open" role="status">
            <Icon name="check" size={20} />
            {t('voters.copy.resultTitle')}
          </p>
          <ul className="flex flex-col gap-2 text-md text-ink">
            <li data-testid="copy-result-copied">
              {t(`voters.copy.copied.${pluralForm(locale, result.copied)}`, {
                count: result.copied,
              })}
            </li>
            <li data-testid="copy-result-skipped">
              {t(`voters.copy.skipped.${pluralForm(locale, result.skipped)}`, {
                count: result.skipped,
              })}
            </li>
            <li data-testid="copy-result-groups">
              {t(`voters.copy.created.${pluralForm(locale, result.groups_created)}`, {
                count: result.groups_created,
              })}
            </li>
          </ul>
          <div className="flex justify-end">
            <Button onClick={onDone} data-testid="copy-close">
              {t('voters.copy.close')}
            </Button>
          </div>
        </div>
      ) : (
        <form
          noValidate
          aria-label={t('voters.copy.title')}
          onSubmit={(event) => {
            event.preventDefault();
            void submit();
          }}
          className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
        >
          <p className="text-base text-ink-soft">{t('voters.copy.text')}</p>

          {sourcesFailed ? (
            <Notice tone="danger" role="alert" data-testid="copy-sources-failed">
              {t('voters.copy.sourcesFailed')}
            </Notice>
          ) : sources !== null && sources.length === 0 ? (
            <Notice tone="info" data-testid="copy-sources-none">
              {t('voters.copy.sourcesNone')}
            </Notice>
          ) : (
            <Select
              label={t('voters.copy.source')}
              value={draft.source}
              onChange={(event) => choose(event.target.value)}
              disabled={busy || sources === null}
              data-testid="copy-source"
              data-autofocus=""
            >
              <option value="">
                {t(sources === null ? 'voters.copy.sourcesLoading' : 'voters.copy.choose')}
              </option>
              {(sources ?? []).map((one) => (
                <option key={one.id} value={one.id}>
                  {one.title}
                </option>
              ))}
            </Select>
          )}

          {draft.source !== '' && source === null ? (
            <p
              className="text-base text-ink-soft"
              role={sourceFailed ? 'alert' : undefined}
              data-testid="copy-groups-state"
            >
              {t(sourceFailed ? 'voters.copy.groupsFailed' : 'voters.copy.groupsLoading')}
            </p>
          ) : null}

          {source !== null ? (
            <fieldset className="flex flex-col gap-2" data-testid="copy-scope">
              <legend className="mb-1 text-md font-medium text-ink">
                {t('voters.copy.scope')}
              </legend>
              <label className="flex min-h-11 cursor-pointer items-center gap-3 text-md text-ink">
                <input
                  type="radio"
                  name="copy-scope"
                  checked={draft.mode === 'all'}
                  onChange={() => patch({ mode: 'all' })}
                  disabled={busy}
                  data-testid="copy-groups-all"
                  className={RADIO}
                />
                {t('voters.copy.all')}
              </label>
              <label className="flex min-h-11 cursor-pointer items-center gap-3 text-md text-ink">
                <input
                  type="radio"
                  name="copy-scope"
                  checked={draft.mode === 'some'}
                  onChange={() => patch({ mode: 'some' })}
                  disabled={busy}
                  data-testid="copy-groups-some"
                  className={RADIO}
                />
                {t('voters.copy.some')}
              </label>

              {draft.mode === 'some' ? (
                <div className="flex flex-col gap-1 pl-1 md:pl-8">
                  {source.groups.length === 0 ? (
                    <p className="text-base text-ink-soft">{t('voters.copy.noGroups')}</p>
                  ) : null}
                  {source.groups.map((group, index) => {
                    const on = draft.groups.includes(group.id);

                    return (
                      <div key={group.id} className="flex flex-col gap-2">
                        <label className="flex min-h-11 cursor-pointer items-center gap-3 text-md text-ink">
                          <input
                            type="checkbox"
                            checked={on}
                            onChange={() =>
                              patch({ groups: toggleCopyGroup(draft.groups, group.id) })
                            }
                            disabled={busy}
                            data-testid={`copy-group-${index + 1}`}
                            className={RADIO}
                          />
                          <span className="min-w-0 flex-1 truncate">{group.name}</span>
                          <b className="text-ink-soft">{group.voters_count}</b>
                        </label>
                        {on ? (
                          <div className="pb-2 pl-8">
                            <Input
                              label={t('voters.copy.rename', { name: group.name })}
                              help={t('voters.copy.renameHelp')}
                              value={draft.rename[group.id] ?? ''}
                              onChange={(event) =>
                                patch({
                                  rename: { ...draft.rename, [group.id]: event.target.value },
                                })
                              }
                              maxLength={NAME_MAX + 20}
                              autoComplete="off"
                              disabled={busy}
                              data-testid={`copy-rename-${index + 1}`}
                            />
                          </div>
                        ) : null}
                      </div>
                    );
                  })}
                  <label className="flex min-h-11 cursor-pointer items-center gap-3 text-md text-ink">
                    <input
                      type="checkbox"
                      checked={draft.ungrouped}
                      onChange={(event) => patch({ ungrouped: event.target.checked })}
                      disabled={busy || source.ungrouped === 0}
                      data-testid="copy-ungrouped"
                      className={RADIO}
                    />
                    <span className="min-w-0 flex-1 truncate">{t('voters.copy.ungrouped')}</span>
                    <b className="text-ink-soft">{source.ungrouped}</b>
                  </label>
                </div>
              ) : null}
            </fieldset>
          ) : null}

          {error ? (
            <Notice tone="danger" role="alert" data-testid="copy-error">
              {error}
            </Notice>
          ) : null}

          <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
            <Button variant="quiet" disabled={busy} onClick={onCancel} data-testid="copy-cancel">
              {t('voters.copy.cancel')}
            </Button>
            <Button type="submit" loading={busy} disabled={!ready} data-testid="copy-submit">
              <span className="inline-flex items-center gap-2">
                <Icon name="check" size={18} />
                {count === null || !ready
                  ? t('voters.copy.submitPlain')
                  : t(`voters.copy.submit.${pluralForm(locale, count)}`, { count })}
              </span>
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
