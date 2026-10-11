'use client';

import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { saveBlob } from '@/components/codes/saveFile';
import { Input, Notice, Select } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import type { AuditFilters, AuditPage as AuditPageData } from '@/lib/api/audit';
import { NO_AUDIT_FILTERS } from '@/lib/api/audit';
import { downloadAuditExport, fetchAudit } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { AuditCard } from './AuditCard';
import { AUDIT_FAMILIES, hasMore, isFiltering } from './auditModel';

const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

const EXPORT_PRIMARY =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-primary bg-primary pr-5 pl-4 text-base font-semibold text-surface shadow-button hover:bg-primary-hover disabled:opacity-60 max-md:flex-1 ' +
  focusRing;

const EXPORT_SECONDARY =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line-strong bg-surface pr-5 pl-4 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft disabled:opacity-60 max-md:flex-1 ' +
  focusRing;

/**
 * Screen A15: the institution's audit log, for an owner. Newest first, 50 entries at a time
 * ("show more" adds the next 50), filtered by family of action, election, person and dates, and
 * exported as CSV or Excel under the same filters. Nothing here can change an entry.
 */
export function AuditPage({
  initial,
  elections,
  people,
}: {
  initial: AuditPageData;
  elections: { id: string; title: string }[];
  people: { id: string; name: string }[];
}) {
  const { t, tIfAny, locale } = useI18n();
  const [filters, setFilters] = useState<AuditFilters>(NO_AUDIT_FILTERS);
  const [data, setData] = useState<AuditPageData>(initial);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [exporting, setExporting] = useState<'csv' | 'xlsx' | null>(null);
  const [exportProblem, setExportProblem] = useState<string | null>(null);
  // Written in the browser's own zone only once mounted, so the first paint matches the server's.
  const mounted = useSyncExternalStore(
    () => () => undefined,
    () => true,
    () => false,
  );
  const ticket = useRef(0);
  const alive = useRef(true);

  useEffect(() => {
    alive.current = true;

    // A filter chosen before the page was interactive (a slow phone) is in the field, not in the
    // state: read it once now, so it is not lost when the controlled fields take over.
    const read = (id: string) =>
      document.querySelector<HTMLInputElement | HTMLSelectElement>(`[data-testid="${id}"]`)?.value;
    const early: AuditFilters = {
      action: read('audit-filter-action') ?? '',
      election: read('audit-filter-election') ?? '',
      user: read('audit-filter-user') ?? '',
      from: read('audit-filter-from') ?? '',
      to: read('audit-filter-to') ?? '',
    };

    const timer = window.setTimeout(() => {
      if (!isFiltering(early)) return;

      setFilters(early);
      void load(early, 1);
    }, 0);

    return () => {
      window.clearTimeout(timer);
      alive.current = false;
    };
    // Once, at mount: `load` only reads refs and the setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function load(next: AuditFilters, page: number) {
    const mine = ++ticket.current;

    setBusy(true);
    setProblem(null);

    try {
      const result = await fetchAudit(next, page);

      if (!alive.current || mine !== ticket.current) return;

      setData((before) =>
        page === 1 ? result : { ...result, items: [...before.items, ...result.items] },
      );
    } catch (caught) {
      if (!alive.current || mine !== ticket.current) return;

      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setProblem(`${t('audit.loadFailed')} ${errorText(error, tIfAny)}`);
    }

    if (alive.current && mine === ticket.current) setBusy(false);
  }

  function choose(change: Partial<AuditFilters>) {
    const next = { ...filters, ...change };

    setFilters(next);
    void load(next, 1);
  }

  async function download(format: 'csv' | 'xlsx') {
    setExporting(format);
    setExportProblem(null);

    try {
      const file = await downloadAuditExport(filters, format);

      saveBlob(file.blob, file.name);
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (alive.current)
        setExportProblem(`${t('audit.export.failed')} ${errorText(error, tIfAny)}`);
    }

    if (alive.current) setExporting(null);
  }

  const filtering = isFiltering(filters);
  const more = hasMore(data.items.length, data.total);

  return (
    <div data-testid="audit-page" className="flex flex-col gap-4.5">
      <p className="text-ink-soft">{t('audit.lede')}</p>

      <section
        aria-label={t('audit.filters.title')}
        className="flex flex-col gap-4 rounded-lg border border-line bg-surface p-5 shadow-1"
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <Select
            label={t('audit.filters.action')}
            value={filters.action}
            onChange={(event) => choose({ action: event.target.value })}
            data-testid="audit-filter-action"
          >
            <option value="">{t('audit.filters.actionAll')}</option>
            {AUDIT_FAMILIES.map((family) => (
              <option key={family} value={`${family}.`}>
                {t(`audit.families.${family}`)}
              </option>
            ))}
          </Select>
          <Select
            label={t('audit.filters.election')}
            value={filters.election}
            onChange={(event) => choose({ election: event.target.value })}
            data-testid="audit-filter-election"
          >
            <option value="">{t('audit.filters.electionAll')}</option>
            {elections.map((election) => (
              <option key={election.id} value={election.id}>
                {election.title}
              </option>
            ))}
          </Select>
          <Select
            label={t('audit.filters.user')}
            value={filters.user}
            onChange={(event) => choose({ user: event.target.value })}
            data-testid="audit-filter-user"
          >
            <option value="">{t('audit.filters.userAll')}</option>
            <option value="system">{t('audit.system')}</option>
            {people.map((person) => (
              <option key={person.id} value={person.id}>
                {person.name}
              </option>
            ))}
          </Select>
          <Input
            type="date"
            label={t('audit.filters.from')}
            value={filters.from}
            max={filters.to || undefined}
            onChange={(event) => choose({ from: event.target.value })}
            data-testid="audit-filter-from"
          />
          <Input
            type="date"
            label={t('audit.filters.to')}
            value={filters.to}
            min={filters.from || undefined}
            onChange={(event) => choose({ to: event.target.value })}
            data-testid="audit-filter-to"
          />
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm font-medium text-ink-soft" aria-live="polite">
            {t(`audit.count.${pluralForm(locale, data.total)}`, { count: data.total })}
          </p>
          <div
            role="group"
            aria-label={t('audit.export.title')}
            className="flex flex-wrap gap-2.5 max-md:w-full"
          >
            <button
              type="button"
              data-testid="audit-export-csv"
              disabled={exporting !== null}
              aria-busy={exporting === 'csv' || undefined}
              onClick={() => void download('csv')}
              className={EXPORT_SECONDARY}
            >
              <Icon path={DOWNLOAD_PATH} size={18} />
              {t('audit.export.csv')}
            </button>
            <button
              type="button"
              data-testid="audit-export-xlsx"
              disabled={exporting !== null}
              aria-busy={exporting === 'xlsx' || undefined}
              onClick={() => void download('xlsx')}
              className={EXPORT_PRIMARY}
            >
              <Icon path={DOWNLOAD_PATH} size={18} />
              {t('audit.export.xlsx')}
            </button>
          </div>
        </div>

        {filtering ? (
          <div>
            <button
              type="button"
              onClick={() => {
                setFilters(NO_AUDIT_FILTERS);
                void load(NO_AUDIT_FILTERS, 1);
              }}
              className={cx(
                'inline-flex min-h-11 items-center rounded-full px-3 text-base font-semibold text-primary-hover hover:bg-primary-soft',
                focusRing,
              )}
            >
              {t('audit.filters.clear')}
            </button>
          </div>
        ) : null}

        {exportProblem ? (
          <Notice tone="danger" role="alert" data-testid="audit-export-error">
            {exportProblem}
          </Notice>
        ) : null}
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="audit-error">
          {problem}
        </Notice>
      ) : null}

      {data.items.length > 0 ? (
        <ul
          aria-label={t('audit.list')}
          aria-busy={busy || undefined}
          data-testid="audit-list"
          className={cx(
            'grid grid-cols-1 gap-3 xl:grid-cols-2 min-[2300px]:grid-cols-3',
            busy && 'opacity-70',
          )}
        >
          {data.items.map((entry, index) => (
            <AuditCard key={entry.id} entry={entry} n={index + 1} local={mounted} />
          ))}
        </ul>
      ) : busy ? (
        <p role="status" className="text-ink-soft">
          {t('audit.loading')}
        </p>
      ) : problem ? null : (
        <div
          data-testid="audit-empty"
          className="flex flex-col items-center gap-2 rounded-lg border border-dashed border-line-strong bg-surface p-8 text-center"
        >
          <span
            aria-hidden="true"
            className="flex size-14 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
          >
            <Icon name="log" size={28} />
          </span>
          <b className="font-display text-xl font-extrabold">{t('audit.empty.title')}</b>
          <p className="max-w-md text-ink-soft">{t('audit.empty.text')}</p>
        </div>
      )}

      {more ? (
        <div className="flex flex-col items-center gap-2">
          <p className="text-sm text-ink-soft">
            {t('audit.moreCount', { shown: data.items.length, total: data.total })}
          </p>
          <button
            type="button"
            data-testid="audit-more"
            disabled={busy}
            onClick={() => void load(filters, data.page + 1)}
            className={cx(EXPORT_SECONDARY, 'max-md:w-full max-md:flex-none')}
          >
            {t('audit.more')}
          </button>
        </div>
      ) : null}
    </div>
  );
}
