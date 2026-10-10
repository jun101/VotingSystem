'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Panel } from '@/components/elections/Panel';
import { Notice, Select } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import {
  cancelImport,
  confirmImport,
  fetchImportRows,
  fetchVoterImport,
  updateImportMapping,
} from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import {
  MAPPING_FIELDS,
  errorFileUrl,
  type ImportRow,
  type ImportRowFilter,
  type MappingField,
  type VoterImport,
} from '@/lib/api/voterImports';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import {
  POLL_MS,
  actionErrorKey,
  applyChange,
  clampPage,
  columnOfValue,
  failureKey,
  mappingChange,
  previewPages,
  previewRange,
  progressCounts,
  progressPercent,
  rowStatusKeys,
  shouldPoll,
  stepOf,
  takenColumns,
  validateMapping,
  valueOfColumn,
} from './checkRules';
import { ImportHero, Stepper } from './ImportShell';

const ZONE =
  'flex min-w-0 flex-col gap-4 rounded-2xl border border-line bg-surface p-3 shadow-1 md:p-5';

const ZONE_TITLE = 'flex items-center gap-2.5 font-display text-xl font-extrabold text-ink';

const ZONE_NUMBER =
  'flex size-7.5 items-center justify-center rounded-full bg-primary font-display text-base text-surface';

const BUTTON_GHOST =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line-strong bg-surface px-5 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft disabled:opacity-50 max-md:w-full ' +
  focusRing;

const BUTTON_PRIMARY =
  'ui-control lift-sm inline-flex min-h-12 items-center justify-center gap-2 rounded-full border border-accent bg-accent pr-5.5 pl-4 text-md font-bold text-deep shadow-button disabled:opacity-50 max-md:w-full ' +
  focusRing;

const BUTTON_OUTLINE =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-primary-line bg-surface px-4 text-base font-semibold text-primary-hover hover:bg-primary-soft max-md:w-full ' +
  focusRing;

const PAGE_BUTTON =
  'ui-control inline-flex size-10 items-center justify-center rounded-full border border-line-strong bg-surface text-ink hover:bg-primary-soft disabled:opacity-40 ' +
  focusRing;

const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

/** The five voter fields in the order of the template, with the cell each row holds. */
const CELL: Record<MappingField, (row: ImportRow) => string | null> = {
  full_name: (row) => row.full_name,
  group: (row) => row.group,
  identifier: (row) => row.identifier,
  email: (row) => row.email,
  phone: (row) => row.phone,
};

type Loaded =
  | { key: string; items: ImportRow[]; total: number; failed?: false }
  | { key: string; failed: true };

function Spinner() {
  return (
    <span
      aria-hidden="true"
      className="size-10 shrink-0 animate-spin rounded-full border-4 border-primary-line border-t-primary motion-reduce:animate-none"
    />
  );
}

/**
 * Screen A10: the check and the import of a voter file, steps 3 and 4. Everything shown comes
 * from the API (the page can be reloaded at any status); while the file is being checked or
 * imported the page reads the import again every two seconds.
 */
export function ImportCheck({ election, initial }: { election: Election; initial: VoterImport }) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();

  const [imp, setImp] = useState<VoterImport>(initial);
  const [gone, setGone] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState<'mapping' | 'confirm' | 'cancel' | null>(null);
  const [chosenFilter, setFilter] = useState<ImportRowFilter>('all');
  const [page, setPage] = useState(1);
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const checkingRef = useRef<HTMLDivElement>(null);

  const votersPage = `/admin/elections/${election.id}/voters`;
  const importPage = `${votersPage}/import`;
  const { status } = imp;
  // "Errors only" has no meaning, and no switch to leave it, once no row is invalid.
  const filter: ImportRowFilter =
    chosenFilter === 'errors' && imp.invalid_rows > 0 ? 'errors' : 'all';

  // The file is being checked or imported: read it again until that ends.
  useEffect(() => {
    if (!shouldPoll(status) || gone) return;

    let stopped = false;
    let timer: ReturnType<typeof setTimeout>;

    const tick = async () => {
      try {
        const next = await fetchVoterImport(imp.id);

        if (stopped) return;

        setImp(next);
      } catch (caught) {
        if (stopped) return;

        if (caught instanceof ApiError && caught.status === 404) {
          setGone(true);

          return;
        }
        // Any other failure (a network blip, a 429): the next tick tries again.
      }

      timer = setTimeout(() => void tick(), POLL_MS);
    };

    timer = setTimeout(() => void tick(), POLL_MS / 2);

    return () => {
      stopped = true;
      clearTimeout(timer);
    };
  }, [status, imp.id, gone]);

  // The rows of the preview: asked again for another page, another filter or another mapping.
  const total = filter === 'errors' ? imp.invalid_rows : imp.total_rows;
  const shownPage = clampPage(page, total);
  const wantedKey = `${imp.id}|${filter}|${shownPage}|${JSON.stringify(imp.mapping)}|${imp.valid_rows}|${imp.invalid_rows}`;

  useEffect(() => {
    if (status !== 'checked') return;

    let stopped = false;

    fetchImportRows(imp.id, filter, shownPage).then(
      (list) => {
        if (!stopped) setLoaded({ key: wantedKey, items: list.items, total: list.total });
      },
      () => {
        if (!stopped) setLoaded({ key: wantedKey, failed: true });
      },
    );

    return () => {
      stopped = true;
    };
  }, [status, imp.id, filter, shownPage, wantedKey]);

  function say(error: unknown) {
    const failure = error instanceof ApiError ? error : new ApiError(0, 'unknown');
    const key = actionErrorKey(failure);

    setProblem((key ? tIfAny(key) : null) ?? errorText(failure, tIfAny));

    // The import moved on without this page (another tab, or the job): read it again.
    if (failure.code === 'import_not_checked' || failure.code === 'import_in_progress') {
      void fetchVoterImport(imp.id).then(setImp, () => undefined);
    }

    if (failure.status === 404) setGone(true);
  }

  async function changeColumn(field: MappingField, value: string) {
    const change = mappingChange(imp.mapping, field, columnOfValue(value));

    if (!change || busy) return;

    if (validateMapping(applyChange(imp.mapping, change), imp.columns.length) !== null) {
      setProblem(t('voterImport.check.mapping.invalid'));

      return;
    }

    setBusy('mapping');
    setProblem(null);

    try {
      setImp(await updateImportMapping(imp.id, change));
      setFilter('all');
      setPage(1);
    } catch (caught) {
      say(caught);
    } finally {
      setBusy(null);
    }
  }

  async function confirm() {
    if (busy) return;

    setBusy('confirm');
    setProblem(null);

    try {
      setImp(await confirmImport(imp.id));
    } catch (caught) {
      say(caught);
    } finally {
      setBusy(null);
    }
  }

  async function cancel() {
    if (busy) return;

    setBusy('cancel');
    setProblem(null);

    try {
      await cancelImport(imp.id);
      router.push(votersPage);
    } catch (caught) {
      say(caught);
      setBusy(null);
    }
  }

  const pages = previewPages(total);
  const rows = loaded && loaded.key === wantedKey ? loaded : null;
  const ready = imp.valid_rows;

  const chips =
    status === 'checked' || status === 'done' || status === 'importing'
      ? [
          {
            icon: 'user' as const,
            label: t(`voterImport.check.chips.rows.${pluralForm(locale, imp.total_rows)}`, {
              count: imp.total_rows,
            }),
          },
          {
            icon: 'check' as const,
            label: t(`voterImport.check.chips.ready.${pluralForm(locale, imp.valid_rows)}`, {
              count: imp.valid_rows,
            }),
          },
          ...(imp.invalid_rows > 0
            ? [
                {
                  icon: 'warn' as const,
                  label: t(
                    `voterImport.check.chips.toFix.${pluralForm(locale, imp.invalid_rows)}`,
                    { count: imp.invalid_rows },
                  ),
                },
              ]
            : []),
        ]
      : [{ icon: 'blank' as const, label: imp.original_name }];

  const heroTitle =
    status === 'done'
      ? t('voterImport.check.done.title')
      : status === 'importing'
        ? t('voterImport.check.progress.title')
        : t('voterImport.check.title');
  const heroText =
    status === 'done'
      ? t('voterImport.check.done.text')
      : status === 'importing'
        ? t('voterImport.check.progress.text')
        : t('voterImport.check.text');

  const counts = progressCounts(imp.processed_rows, imp.total_rows);
  const percent = progressPercent(imp.processed_rows, imp.total_rows);

  const cancelButton = (
    <button
      type="button"
      data-testid="check-cancel"
      disabled={busy !== null}
      onClick={() => void cancel()}
      className={BUTTON_GHOST}
    >
      {busy === 'cancel'
        ? t('voterImport.check.confirm.cancelling')
        : t('voterImport.check.confirm.cancel')}
    </button>
  );

  return (
    <div data-testid="check-page" data-status={status} className="flex flex-col gap-4.5">
      <ImportHero
        election={election}
        title={heroTitle}
        text={heroText}
        badge={t('voterImport.check.badge')}
        chips={chips}
        back={{ href: votersPage, label: t('voterImport.back') }}
      />

      <Stepper current={stepOf(status)} />

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <div className="flex min-w-0 flex-1 flex-col gap-4.5">
          {gone ? (
            <Notice tone="warm" role="alert" data-testid="check-gone">
              <p>{t('voterImport.check.gone')}</p>
              <Link href={votersPage} className="mt-2 inline-block font-semibold underline">
                {t('voterImport.check.done.voters')}
              </Link>
            </Notice>
          ) : null}

          {problem ? (
            <Notice tone="danger" role="alert" data-testid="check-error">
              {problem}
            </Notice>
          ) : null}

          {!gone && status === 'uploaded' ? (
            <section aria-label={t('voterImport.check.checking.title')} className={ZONE}>
              <div
                ref={checkingRef}
                role="status"
                data-testid="check-checking"
                className="flex items-center gap-4"
              >
                <Spinner />
                <div>
                  <h3 className="font-display text-xl font-extrabold text-ink">
                    {t('voterImport.check.checking.title')}
                  </h3>
                  <p className="text-base text-ink-soft">{t('voterImport.check.checking.text')}</p>
                </div>
              </div>
              <div className="flex">{cancelButton}</div>
            </section>
          ) : null}

          {!gone && status === 'failed' ? (
            <section
              aria-label={t('voterImport.check.failed.title')}
              data-testid="check-failed"
              data-failure={imp.failure ?? ''}
              className={ZONE}
            >
              <div className="flex items-start gap-4">
                <span
                  aria-hidden="true"
                  className="flex size-12 shrink-0 items-center justify-center rounded-full bg-warm-soft text-warm-ink"
                >
                  <Icon name="warn" size={26} />
                </span>
                <div>
                  <h3 className="font-display text-xl font-extrabold text-ink">
                    {t('voterImport.check.failed.title')}
                  </h3>
                  <p data-testid="check-failure" className="mt-1 text-base text-ink-soft">
                    {t(failureKey(imp.failure) as 'voterImport.check.failure.unknown')}
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap gap-3">
                <Link
                  href={importPage}
                  data-testid="check-retry"
                  className={cx(BUTTON_PRIMARY, 'inline-flex w-auto max-md:w-full')}
                >
                  <Icon name="left" size={18} />
                  {t('voterImport.check.failed.retry')}
                </Link>
              </div>
            </section>
          ) : null}

          {!gone && status === 'checked' ? (
            <>
              <section aria-label={t('voterImport.check.summary.zone')} className={ZONE}>
                <div className="flex flex-col gap-1">
                  <h3 className={ZONE_TITLE}>
                    <span aria-hidden="true" className={ZONE_NUMBER}>
                      {1}
                    </span>
                    {t('voterImport.check.summary.title')}
                  </h3>
                  <p className="text-base break-words text-ink-soft">
                    {t('voterImport.check.summary.file', {
                      name: imp.original_name,
                      rows: t(
                        `voterImport.check.chips.rows.${pluralForm(locale, imp.total_rows)}`,
                        { count: imp.total_rows },
                      ),
                    })}
                  </p>
                </div>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                  <Tile
                    testId="check-total"
                    icon="blank"
                    value={imp.total_rows}
                    label={t('voterImport.check.summary.total')}
                    tone="bg-surface-alt text-ink"
                    iconTone="bg-primary-soft text-primary-hover"
                  />
                  <Tile
                    testId="check-valid"
                    icon="check"
                    value={imp.valid_rows}
                    label={t('voterImport.check.summary.valid')}
                    tone="bg-ok-soft text-ok-ink"
                    iconTone="bg-surface text-ok-ink"
                  />
                  <Tile
                    testId="check-invalid"
                    icon="warn"
                    value={imp.invalid_rows}
                    label={t('voterImport.check.summary.invalid')}
                    tone="bg-warm-soft text-warm-ink"
                    iconTone="bg-surface text-warm-ink"
                  />
                  <Tile
                    testId="check-duplicates"
                    icon="user"
                    value={imp.duplicate_rows}
                    label={t('voterImport.check.summary.duplicates')}
                    tone="bg-primary-soft text-primary-hover"
                    iconTone="bg-surface text-primary-hover"
                  />
                </div>

                <div data-testid="check-new-groups" className="flex flex-col gap-2">
                  {imp.new_groups.length > 0 ? (
                    <>
                      <p className="text-base font-semibold text-ink">
                        {t(
                          `voterImport.check.summary.groups.${pluralForm(locale, imp.new_groups.length)}`,
                          { count: imp.new_groups.length },
                        )}
                      </p>
                      <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
                        {imp.new_groups.map((name) => (
                          <li
                            key={name}
                            className="rounded-full bg-primary-soft px-3 py-1 text-base font-medium break-words text-primary-hover"
                          >
                            {name}
                          </li>
                        ))}
                      </ul>
                    </>
                  ) : (
                    <p className="text-base text-ink-soft">
                      {t('voterImport.check.summary.noGroups')}
                    </p>
                  )}
                </div>
              </section>

              <section aria-label={t('voterImport.check.mapping.zone')} className={ZONE}>
                <div className="flex flex-col gap-1">
                  <h3 className={ZONE_TITLE}>
                    <span aria-hidden="true" className={ZONE_NUMBER}>
                      {2}
                    </span>
                    {t('voterImport.check.mapping.title')}
                  </h3>
                  <p className="text-base text-ink-soft">{t('voterImport.check.mapping.text')}</p>
                </div>

                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                  {MAPPING_FIELDS.map((field) => {
                    const taken = takenColumns(imp.mapping, field);

                    return (
                      <Select
                        key={field}
                        data-testid={`map-select-${field}`}
                        label={
                          t(`voterImport.check.mapping.field.${field}`) +
                          (field === 'full_name' ? ' *' : '')
                        }
                        value={valueOfColumn(imp.mapping?.[field])}
                        disabled={busy !== null}
                        onChange={(event) => void changeColumn(field, event.target.value)}
                      >
                        {field === 'full_name' ? null : (
                          <option value="">{t('voterImport.check.mapping.skip')}</option>
                        )}
                        {imp.columns.map((name, index) => (
                          <option key={index} value={String(index)} disabled={taken.has(index)}>
                            {name.trim() === ''
                              ? t('voterImport.check.mapping.column', { n: index + 1 })
                              : name}
                          </option>
                        ))}
                      </Select>
                    );
                  })}
                </div>
              </section>

              <section aria-label={t('voterImport.check.preview.zone')} className={ZONE}>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-3">
                  <h3 className={ZONE_TITLE}>
                    <span aria-hidden="true" className={ZONE_NUMBER}>
                      {3}
                    </span>
                    {t('voterImport.check.preview.title')}
                  </h3>
                  <div className="flex w-full flex-wrap items-center gap-3 md:ml-auto md:w-auto">
                    {imp.invalid_rows > 0 ? (
                      <button
                        type="button"
                        role="switch"
                        aria-checked={filter === 'errors'}
                        data-testid="check-filter-errors"
                        onClick={() => {
                          setFilter(filter === 'errors' ? 'all' : 'errors');
                          setPage(1);
                        }}
                        className={cx(
                          'ui-control inline-flex min-h-11 items-center gap-2.5 rounded-full border px-4 text-base font-semibold max-md:w-full',
                          filter === 'errors'
                            ? 'border-primary bg-primary text-surface'
                            : 'border-line-strong bg-surface text-ink hover:bg-primary-soft',
                          focusRing,
                        )}
                      >
                        <Icon name="warn" size={18} />
                        {t('voterImport.check.preview.onlyErrors', { count: imp.invalid_rows })}
                      </button>
                    ) : null}
                    {imp.has_error_file ? (
                      <a
                        href={errorFileUrl(imp.id)}
                        download="erreurs-electeurs.xlsx"
                        data-testid="check-error-file"
                        className={BUTTON_OUTLINE}
                      >
                        <Icon path={DOWNLOAD_PATH} size={18} />
                        {t('voterImport.check.preview.errorFile')}
                      </a>
                    ) : null}
                  </div>
                </div>

                <div
                  role="region"
                  tabIndex={0}
                  aria-label={t('voterImport.check.preview.scroll')}
                  aria-busy={rows === null}
                  className={cx('overflow-x-auto rounded-lg border border-line', focusRing)}
                >
                  <table
                    data-testid="check-rows"
                    className="w-full min-w-max border-collapse text-base"
                  >
                    <thead>
                      <tr className="bg-primary-soft text-left text-primary-hover">
                        <th scope="col" className="w-14 px-3 py-2 font-bold whitespace-nowrap">
                          {t('voterImport.check.preview.line')}
                        </th>
                        <th scope="col" className="px-3 py-2 font-bold whitespace-nowrap">
                          {t('voterImport.check.preview.state')}
                        </th>
                        {MAPPING_FIELDS.map((field) => (
                          <th
                            key={field}
                            scope="col"
                            className="px-3 py-2 font-bold whitespace-nowrap"
                          >
                            {t(`voterImport.check.mapping.field.${field}`)}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {rows === null ? (
                        <tr>
                          <td colSpan={7} className="px-3 py-6 text-center text-ink-soft">
                            {t('voterImport.check.preview.loading')}
                          </td>
                        </tr>
                      ) : rows.failed ? (
                        <tr>
                          <td colSpan={7} className="px-3 py-6 text-center text-danger">
                            {t('voterImport.check.preview.loadFailed')}
                          </td>
                        </tr>
                      ) : rows.items.length === 0 ? (
                        <tr>
                          <td colSpan={7} className="px-3 py-6 text-center text-ink-soft">
                            {t('voterImport.check.preview.none')}
                          </td>
                        </tr>
                      ) : (
                        rows.items.map((row, index) => (
                          <PreviewRow key={row.line} n={index + 1} row={row} mapped={imp.mapping} />
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {rows && !rows.failed && rows.total > 0 ? (
                  <nav
                    data-testid="check-pager"
                    aria-label={t('voterImport.check.preview.pager')}
                    className="flex flex-col items-center justify-between gap-3 md:flex-row"
                  >
                    <p data-testid="check-range" className="text-base text-ink-soft">
                      {t(`voterImport.check.preview.range.${pluralForm(locale, rows.total)}`, {
                        ...previewRange(shownPage, rows.total),
                        total: rows.total,
                      })}
                    </p>
                    {pages > 1 ? (
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          data-testid="check-prev"
                          aria-label={t('voterImport.check.preview.prev')}
                          disabled={shownPage <= 1}
                          onClick={() => setPage(shownPage - 1)}
                          className={PAGE_BUTTON}
                        >
                          <Icon name="left" size={18} />
                        </button>
                        <span className="px-1 text-base text-ink-soft">
                          {t('voterImport.check.preview.page', { page: shownPage, pages })}
                        </span>
                        <button
                          type="button"
                          data-testid="check-next"
                          aria-label={t('voterImport.check.preview.next')}
                          disabled={shownPage >= pages}
                          onClick={() => setPage(shownPage + 1)}
                          className={PAGE_BUTTON}
                        >
                          <Icon name="right" size={18} />
                        </button>
                      </div>
                    ) : null}
                  </nav>
                ) : null}
              </section>

              <div
                role="region"
                aria-label={t('voterImport.check.confirm.zone')}
                className="sticky bottom-3 z-10 flex flex-col gap-3 rounded-2xl border border-line bg-surface p-3 shadow-3 md:flex-row md:items-center md:p-4"
              >
                <p className="min-w-0 flex-1 text-base text-ink-soft">
                  {ready > 0 ? (
                    <>
                      <b className="text-ink">
                        {t(`voterImport.check.confirm.ready.${pluralForm(locale, ready)}`, {
                          count: ready,
                        })}
                      </b>{' '}
                      {imp.invalid_rows > 0
                        ? t(
                            `voterImport.check.confirm.rest.${pluralForm(locale, imp.invalid_rows)}`,
                            { count: imp.invalid_rows },
                          )
                        : null}
                    </>
                  ) : (
                    t('voterImport.check.confirm.nothing')
                  )}
                </p>
                <div className="flex flex-col-reverse gap-2 md:flex-row md:items-center">
                  {cancelButton}
                  <button
                    type="button"
                    data-testid="check-confirm"
                    disabled={ready === 0 || busy !== null}
                    onClick={() => void confirm()}
                    className={BUTTON_PRIMARY}
                  >
                    <Icon name="check" size={20} />
                    {busy === 'confirm'
                      ? t('voterImport.check.confirm.going')
                      : t(`voterImport.check.confirm.go.${pluralForm(locale, ready)}`, {
                          count: ready,
                        })}
                  </button>
                </div>
              </div>
            </>
          ) : null}

          {!gone && status === 'importing' ? (
            <section aria-label={t('voterImport.check.progress.title')} className={ZONE}>
              <h3 className={ZONE_TITLE}>{t('voterImport.check.progress.title')}</h3>
              <div
                role="progressbar"
                data-testid="import-progress"
                aria-label={t('voterImport.check.progress.label')}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent}
                className="h-4 w-full overflow-hidden rounded-full bg-primary-soft"
              >
                <div
                  aria-hidden="true"
                  style={{ width: `${percent}%` }}
                  className="duration-base h-full rounded-full bg-primary transition-[width]"
                />
              </div>
              <p data-testid="import-progress-text" className="text-md font-semibold text-ink">
                {t('voterImport.check.progress.counts', counts)}
              </p>
              <p className="text-base text-ink-soft">{t('voterImport.check.progress.text')}</p>
            </section>
          ) : null}

          {!gone && status === 'done' ? (
            <section
              aria-label={t('voterImport.check.done.title')}
              data-testid="import-done"
              className={ZONE}
            >
              <div className="flex items-start gap-4">
                <span
                  aria-hidden="true"
                  className="flex size-14 shrink-0 items-center justify-center rounded-full bg-ok-soft text-ok-ink"
                >
                  <Icon name="check" size={30} />
                </span>
                <div>
                  <h3 className="font-display text-2xl font-extrabold text-ink">
                    {t('voterImport.check.done.title')}
                  </h3>
                  <p
                    data-testid="import-done-count"
                    className="mt-1 text-lg font-semibold text-ok-ink"
                  >
                    {t(`voterImport.check.done.count.${pluralForm(locale, imp.imported_rows)}`, {
                      count: imp.imported_rows,
                    })}
                  </p>
                </div>
              </div>

              <div
                data-testid="import-done-errors"
                className="flex flex-wrap items-center gap-3 rounded-lg bg-surface-alt p-3"
              >
                <span
                  aria-hidden="true"
                  className={cx(
                    'flex size-9 items-center justify-center rounded-full',
                    imp.invalid_rows > 0 ? 'bg-warm-soft text-warm-ink' : 'bg-ok-soft text-ok-ink',
                  )}
                >
                  <Icon name={imp.invalid_rows > 0 ? 'warn' : 'check'} size={18} />
                </span>
                <p className="min-w-0 flex-1 text-base font-semibold text-ink">
                  {imp.invalid_rows > 0
                    ? t(`voterImport.check.done.errors.${pluralForm(locale, imp.invalid_rows)}`, {
                        count: imp.invalid_rows,
                      })
                    : t('voterImport.check.done.noErrors')}
                </p>
                {imp.has_error_file ? (
                  <a
                    href={errorFileUrl(imp.id)}
                    download="erreurs-electeurs.xlsx"
                    data-testid="import-done-error-file"
                    className={BUTTON_OUTLINE}
                  >
                    <Icon path={DOWNLOAD_PATH} size={18} />
                    {t('voterImport.check.done.errorFile')}
                  </a>
                ) : null}
              </div>

              <div className="flex">
                <Link
                  href={votersPage}
                  data-testid="import-done-voters"
                  className={cx(BUTTON_PRIMARY, 'inline-flex')}
                >
                  <Icon name="user" size={20} />
                  {t('voterImport.check.done.voters')}
                </Link>
              </div>
            </section>
          ) : null}
        </div>

        <aside
          aria-label={t('voterImport.check.rail.label')}
          className="flex w-full flex-wrap items-start gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col 2xl:flex-nowrap 2xl:items-stretch"
        >
          <Panel
            tone="steps"
            icon="check"
            title={t('voterImport.check.rail.title')}
            id="check-next-title"
            testId="check-rail"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <ol className="m-0 flex list-none flex-col gap-3 p-0">
              {(['background', 'codes'] as const).map((key, index) => (
                <li key={key} className="flex items-start gap-3">
                  <span
                    aria-hidden="true"
                    className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft font-display text-base font-extrabold text-primary-hover"
                  >
                    {index + 1}
                  </span>
                  <span className="text-base text-ink-soft">
                    <b className="block text-ink">{t(`voterImport.check.rail.${key}.title`)}</b>
                    {t(`voterImport.check.rail.${key}.text`)}
                  </span>
                </li>
              ))}
            </ol>
          </Panel>
        </aside>
      </div>
    </div>
  );
}

/** One of the four tiles of the summary. */
function Tile({
  testId,
  icon,
  value,
  label,
  tone,
  iconTone,
}: {
  testId: string;
  icon: 'blank' | 'check' | 'warn' | 'user';
  value: number;
  label: string;
  tone: string;
  iconTone: string;
}) {
  return (
    <div
      data-testid={testId}
      className={cx('flex min-w-0 items-center gap-3 rounded-lg p-3', tone)}
    >
      <span
        aria-hidden="true"
        className={cx('flex size-10 shrink-0 items-center justify-center rounded-full', iconTone)}
      >
        <Icon name={icon} size={20} />
      </span>
      <span className="flex min-w-0 flex-col">
        <b className="font-display text-2xl leading-tight font-extrabold">{value}</b>
        <small className="text-sm">{label}</small>
      </span>
    </div>
  );
}

/** One row of the preview: its line in the file, its state in words, then the cells. */
function PreviewRow({
  n,
  row,
  mapped,
}: {
  n: number;
  row: ImportRow;
  mapped: VoterImport['mapping'];
}) {
  const { t } = useI18n();
  const keys = rowStatusKeys(row);
  const tone =
    row.status === 'ok'
      ? 'bg-ok-soft text-ok-ink'
      : row.status === 'duplicate'
        ? 'bg-primary-soft text-primary-hover'
        : 'bg-warm-soft text-warm-ink';

  return (
    <tr
      data-testid={`check-row-${n}`}
      data-status={row.status}
      className={cx('border-t border-line-soft', row.status !== 'ok' && 'bg-warm-softer')}
    >
      <th scope="row" className="bg-surface-alt px-3 py-2 text-left font-medium text-ink-soft">
        {row.line}
      </th>
      <td className="px-3 py-2">
        <span
          data-testid={`check-row-status-${n}`}
          className={cx(
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-sm font-bold whitespace-nowrap',
            tone,
          )}
        >
          <Icon
            name={row.status === 'ok' ? 'check' : row.status === 'duplicate' ? 'user' : 'warn'}
            size={14}
          />
          {keys.map((key) => t(key as 'voterImport.check.status.ok')).join(' · ')}
        </span>
      </td>
      {MAPPING_FIELDS.map((field) => {
        const value = CELL[field](row);
        const skipped = field !== 'full_name' && mapped !== null && mapped[field] === null;

        return (
          <td key={field} className="px-3 py-2 whitespace-nowrap text-ink">
            {value !== null && value !== '' ? (
              value
            ) : field === 'full_name' && !skipped ? (
              <span className="text-danger">{t('voterImport.check.preview.empty')}</span>
            ) : null}
          </td>
        );
      })}
    </tr>
  );
}
