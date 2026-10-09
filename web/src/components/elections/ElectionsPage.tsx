'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { Icon } from '@/components/admin/Icon';
import { linkAccent } from '@/components/admin/classes';
import { Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { duplicateElection } from '@/lib/api/browser';
import {
  ELECTION_STATUSES,
  type Election,
  type ElectionFilters,
  type ElectionList,
  type ElectionStatus,
} from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { DeleteElectionDialog } from './DeleteElectionDialog';
import { ElectionCard } from './ElectionCard';
import { RevealList } from './RevealList';

const PLUS = 'M12 5v14M5 12h14';

const chip =
  'ui-control inline-flex h-11 items-center gap-2 rounded border px-3 text-base font-semibold focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

function filterClass(active: boolean): string {
  return cx(
    chip,
    active
      ? 'border-primary bg-primary-soft text-primary'
      : 'border-line-strong bg-surface text-ink hover:bg-surface-alt',
  );
}

/**
 * Screen A03: the status tiles, the year chips and the "new election" button on one compact
 * row, then the grid of cards, the first cell of which is the "new election" tile. The filters
 * live in the address (`?status=…&year=…`): a tile or a chip changes the address, the page is
 * read again by the server and nothing reloads.
 */
export function ElectionsPage({ list, filters }: { list: ElectionList; filters: ElectionFilters }) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [deleting, setDeleting] = useState<Election | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  function show(next: ElectionFilters) {
    const query = new URLSearchParams();

    if (next.status) query.set('status', next.status);
    if (next.year) query.set('year', String(next.year));

    const address = query.size > 0 ? `/admin/elections?${query.toString()}` : '/admin/elections';

    startTransition(() => router.push(address, { scroll: false }));
  }

  async function duplicate(election: Election) {
    setProblem(null);
    setBusyId(election.id);

    try {
      await duplicateElection(election.id);
      startTransition(() => router.refresh());
    } catch (caught) {
      setProblem(
        errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny),
      );
    } finally {
      setBusyId(null);
    }
  }

  // A status has a tile when it has elections (or when it is the one being looked at).
  const tiles: ElectionStatus[] = ELECTION_STATUSES.filter(
    (status) => list.counts[status] > 0 || filters.status === status,
  );

  const empty = list.items.length === 0;
  const nothingAtAll = empty && list.counts.all === 0 && list.counts.archived === 0;

  return (
    <div
      data-testid="elections-page"
      aria-busy={pending ? 'true' : undefined}
      className="flex flex-col gap-4"
    >
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div
          role="group"
          aria-label={t('elections.list.tilesLabel')}
          data-testid="elections-tiles"
          className="flex flex-wrap gap-2"
        >
          <button
            type="button"
            aria-pressed={!filters.status}
            data-active={!filters.status}
            data-count={list.counts.all}
            data-testid="tile-all"
            onClick={() => show({ year: filters.year })}
            className={filterClass(!filters.status)}
          >
            <span>{t('elections.list.all')}</span>
            <span className="rounded-full bg-canvas px-2 text-sm text-ink-2">
              {list.counts.all}
            </span>
          </button>
          {tiles.map((status) => (
            <button
              key={status}
              type="button"
              aria-pressed={filters.status === status}
              data-active={filters.status === status}
              data-count={list.counts[status]}
              data-testid={`tile-${status}`}
              onClick={() =>
                show({ status: filters.status === status ? undefined : status, year: filters.year })
              }
              className={filterClass(filters.status === status)}
            >
              <span>{t(`elections.status.${status}`)}</span>
              <span className="rounded-full bg-canvas px-2 text-sm text-ink-2">
                {list.counts[status]}
              </span>
            </button>
          ))}
        </div>

        {list.years.length > 0 ? (
          <div
            role="group"
            aria-label={t('elections.list.yearsLabel')}
            data-testid="year-chips"
            className="flex flex-wrap gap-2"
          >
            {list.years.map((year) => (
              <button
                key={year}
                type="button"
                aria-pressed={filters.year === year}
                data-active={filters.year === year}
                data-testid={`year-${year}`}
                onClick={() =>
                  show({ status: filters.status, year: filters.year === year ? undefined : year })
                }
                className={filterClass(filters.year === year)}
              >
                {year}
              </button>
            ))}
          </div>
        ) : null}

        <Link
          href="/admin/elections/new"
          data-testid="election-new-button"
          className={cx(linkAccent, 'gap-2 sm:ml-auto')}
        >
          <Icon path={PLUS} />
          {t('elections.list.newButton')}
        </Link>
      </div>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="elections-action-error">
          {t('elections.list.actionError')} {problem}
        </Notice>
      ) : null}

      <RevealList
        aria-label={t('elections.list.gridLabel')}
        data-testid="elections-grid"
        className="grid grid-cols-[repeat(auto-fill,minmax(17.5rem,1fr))] gap-4"
      >
        <li data-testid="election-new-tile" className="flex min-w-0">
          <Link
            href="/admin/elections/new"
            className="ui-control flex min-h-24 w-full flex-col items-start justify-center gap-1 rounded-lg border-2 border-dashed border-primary-line bg-surface p-4 text-primary hover:border-primary hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
          >
            <span className="flex items-center gap-2 font-display text-lg font-bold">
              <Icon path={PLUS} size={20} />
              {t('elections.list.newTile')}
            </span>
            {empty ? (
              <span data-testid="elections-empty" className="text-sm text-ink-soft">
                {t(nothingAtAll ? 'elections.list.empty' : 'elections.list.emptyFiltered')}
              </span>
            ) : null}
          </Link>
        </li>
        {list.items.map((election, index) => (
          <ElectionCard
            key={election.id}
            election={election}
            position={index + 1}
            busy={busyId === election.id}
            onDuplicate={duplicate}
            onDelete={setDeleting}
          />
        ))}
      </RevealList>

      {list.total > list.items.length ? (
        <Notice tone="info">{t('elections.list.truncated')}</Notice>
      ) : null}

      {deleting ? (
        <DeleteElectionDialog
          election={deleting}
          onCancel={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            startTransition(() => router.refresh());
          }}
        />
      ) : null}
    </div>
  );
}
