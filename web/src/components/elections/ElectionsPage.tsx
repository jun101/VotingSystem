'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, useTransition, type CSSProperties } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { linkPrimary } from '@/components/admin/classes';
import { Button, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { duplicateElection, fetchElectionsPage } from '@/lib/api/browser';
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
import { ElectionsRail } from './ElectionsRail';
import { RevealList } from './RevealList';
import { STATUS_ICONS } from './statusIcon';

const focus =
  'focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

/** A status tile: the figure, its label, and a line along the bottom in the colour of the status. */
function tileClass(active: boolean): string {
  return cx(
    'ui-control lift-sm tile-line inline-flex h-12 items-center gap-2 rounded-lg pr-4 pl-2 text-base font-medium shadow-1',
    active ? 'bg-primary text-surface' : 'bg-surface text-ink hover:shadow-2',
    focus,
  );
}

const TILE_LINES: Record<ElectionStatus | 'all', string> = {
  all: 'var(--color-primary)',
  draft: 'var(--color-status-draft)',
  scheduled: 'var(--color-status-scheduled)',
  open: 'var(--color-status-open)',
  closed: 'var(--color-ink-soft)',
  published: 'var(--color-status-published)',
  archived: 'var(--color-line-strong)',
};

/** The round icon of a tile: the colours of its status, or glass on the selected tile. */
const TILE_ICON_COLORS: Record<ElectionStatus | 'all', string> = {
  all: 'bg-primary-soft text-primary',
  draft: 'bg-status-draft-soft text-status-draft',
  scheduled: 'bg-status-scheduled-soft text-status-scheduled',
  open: 'bg-status-open-soft text-status-open',
  closed: 'bg-line-soft text-ink-soft',
  published: 'bg-status-published-soft text-status-published',
  archived: 'bg-line-soft text-ink-soft',
};

function TileIcon({
  name,
  status,
  active,
}: {
  name: IconName;
  status: ElectionStatus | 'all';
  active: boolean;
}) {
  return (
    <span
      aria-hidden="true"
      className={cx(
        'flex size-8 shrink-0 items-center justify-center rounded-full',
        active ? 'bg-surface/20 text-surface' : TILE_ICON_COLORS[status],
      )}
    >
      <Icon name={name} size={17} />
    </span>
  );
}

function yearClass(active: boolean): string {
  return cx(
    'ui-control lift-sm inline-flex h-11 items-center rounded-full border px-4 text-base font-medium',
    active
      ? 'border-primary-line bg-primary-soft text-status-scheduled'
      : 'border-line bg-surface text-ink hover:bg-primary-soft',
    focus,
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
  // The pages after the first, read here; they belong to the list they were read for, so a new
  // list from the server (another filter, a refresh) drops them.
  const [more, setMore] = useState<{
    for: ElectionList;
    items: Election[];
    page: number;
    done: boolean;
  }>({ for: list, items: [], page: 1, done: false });
  const [loadingMore, setLoadingMore] = useState(false);
  const extra =
    more.for === list ? more : { for: list, items: [] as Election[], page: 1, done: false };
  const items = [...list.items, ...extra.items];

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

  async function showMore() {
    setProblem(null);
    setLoadingMore(true);

    try {
      const next = await fetchElectionsPage(filters, extra.page + 1);
      const known = new Set(items.map((item) => item.id));
      const fresh = next.items.filter((item) => !known.has(item.id));

      setMore({
        for: list,
        items: [...extra.items, ...fresh],
        page: extra.page + 1,
        // A page with nothing new ends the list, whatever the total says.
        done: fresh.length === 0,
      });
    } catch (caught) {
      setProblem(
        errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny),
      );
    } finally {
      setLoadingMore(false);
    }
  }

  const empty = items.length === 0;
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
            style={
              {
                '--tile-line': filters.status ? TILE_LINES.all : 'var(--color-surface)',
              } as CSSProperties
            }
            className={tileClass(!filters.status)}
          >
            <TileIcon name="list" status="all" active={!filters.status} />
            <b className="font-display text-xl font-extrabold">{list.counts.all}</b>
            <span>{t('elections.list.all')}</span>
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
              style={
                {
                  '--tile-line':
                    filters.status === status ? 'var(--color-surface)' : TILE_LINES[status],
                } as CSSProperties
              }
              className={tileClass(filters.status === status)}
            >
              <TileIcon
                name={STATUS_ICONS[status]}
                status={status}
                active={filters.status === status}
              />
              <b className="font-display text-xl font-extrabold">{list.counts[status]}</b>
              <span>{t(`elections.status.${status}`)}</span>
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
                className={yearClass(filters.year === year)}
              >
                {year}
              </button>
            ))}
          </div>
        ) : null}

        <Link
          href="/admin/elections/new"
          data-testid="election-new-button"
          className={cx(linkPrimary, 'shimmer-sweep min-h-12 gap-2 pr-6 pl-4 sm:ml-auto')}
        >
          <Icon name="plus" />
          {t('elections.list.newButton')}
        </Link>
      </div>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="elections-action-error">
          {t('elections.list.actionError')} {problem}
        </Notice>
      ) : null}

      <div className="flex items-start gap-4">
        <div
          data-testid="elections-zone"
          className="flex min-w-0 flex-1 flex-col gap-4 rounded-2xl border border-line bg-surface p-1.5 shadow-1 md:p-3 2xl:p-5"
        >
          <RevealList
            aria-label={t('elections.list.gridLabel')}
            data-testid="elections-grid"
            className="grid grid-cols-[repeat(auto-fill,minmax(min(17rem,100%),1fr))] gap-4 2xl:grid-cols-[repeat(auto-fill,minmax(min(23.75rem,100%),1fr))]"
          >
            <li
              data-testid="election-new-tile"
              className="lift flex min-h-32 min-w-0 rounded-lg border-2 border-dashed border-primary-line bg-surface/60 focus-within:ring-4 focus-within:ring-primary-soft hover:bg-surface"
            >
              <Link
                href="/admin/elections/new"
                className="flex w-full flex-col items-center justify-center gap-2 rounded-lg p-4 text-center font-medium text-primary-hover focus-visible:outline-none"
              >
                <span
                  aria-hidden="true"
                  className="bob flex size-14 items-center justify-center rounded-full bg-primary text-surface shadow-button"
                >
                  <Icon name="plus" size={28} />
                </span>
                <span className="font-display text-lg font-extrabold">
                  {t('elections.list.newTile')}
                </span>
                {empty ? (
                  <span data-testid="elections-empty" className="text-sm font-normal text-ink-soft">
                    {t(nothingAtAll ? 'elections.list.empty' : 'elections.list.emptyFiltered')}
                  </span>
                ) : null}
              </Link>
            </li>
            {items.map((election, index) => (
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

          {list.total > items.length && !extra.done ? (
            <div className="flex justify-center">
              <Button
                type="button"
                variant="secondary"
                loading={loadingMore}
                onClick={showMore}
                data-testid="elections-show-more"
              >
                {t('elections.list.showMore')}
              </Button>
            </div>
          ) : null}
        </div>
        <ElectionsRail elections={items} />
      </div>

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
