'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, useTransition } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { PANEL_ASIDE, Panel } from '@/components/elections/Panel';
import { RevealList } from '@/components/elections/RevealList';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { LiveDot } from '@/components/motion';
import { Input, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { fetchGroups, fetchVoters } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import type { GroupList, Voter, VoterFilters, VoterGroup, VoterList } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import { DeleteGroupDialog } from './DeleteGroupDialog';
import { DeleteVoterDialog } from './DeleteVoterDialog';
import { GroupModal } from './GroupModal';
import { GroupsCard } from './GroupsCard';
import { MergeGroupDialog } from './MergeGroupDialog';
import { VoterCard } from './VoterCard';
import { VoterModal } from './VoterModal';
import { SEARCH_MAX, queryOf, sameFilters } from './voterQuery';
import { rulesOf } from './voterRules';
import { groupsText, pageCount, pageItems, rangeText, votersText } from './voterText';

/** The text colour of the badge on the header band, by status (as on the election page). */
const BADGE_TEXT: Record<ElectionStatus, string> = {
  draft: 'text-status-draft',
  scheduled: 'text-status-scheduled',
  open: 'text-status-open',
  closed: 'text-ink-soft',
  published: 'text-status-published',
  archived: 'text-ink-soft',
};

const CHIP =
  'inline-flex h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass pr-3.5 pl-2.5 text-base font-medium';

/** The coral main action on the header band. */
const ADD =
  'ui-control lift-sm inline-flex h-12 items-center justify-center gap-2 rounded-full border border-accent bg-accent pr-5.5 pl-4 text-md font-bold text-deep shadow-button max-md:w-full ' +
  focusRing;

/** A filter chip under the search: a group name and its count. */
const FILTER =
  'ui-control inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border px-3.5 text-base font-medium md:min-h-9 ' +
  focusRing;

const PAGE_BUTTON =
  'ui-control inline-flex size-11 items-center justify-center rounded-full border text-base font-bold md:size-9 disabled:opacity-40 ' +
  focusRing;

/** The search is sent when typing stops for this long. */
const DEBOUNCE_MS = 300;

/** What the voter modal is for: a new voter, or this one. */
type FormState = { mode: 'new' } | { mode: 'edit'; voter: Voter };

/** What the group modal is for: a new group, or this one. */
type GroupFormState = { mode: 'new' } | { mode: 'edit'; group: VoterGroup };

/**
 * Screen A08: the voters of one election. A header band with the status, the title, the chips
 * and the main action, then one white zone (search, group chips, voter cards two per row, the
 * pager); the rail beside it from 1600 px and under it below holds the groups card and the
 * placeholder of the codes. Reading is always allowed; what can change follows the election's
 * status (`rulesOf`). The page, the search and the group are kept in the address.
 *
 * The list is read again after every change, with the groups (their counts), so what is shown
 * is what the server holds.
 */
export function VotersPage({
  election,
  initialFilters,
  initial,
  initialGroups,
}: {
  election: Election;
  initialFilters: VoterFilters;
  initial: VoterList;
  initialGroups: GroupList;
}) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const [, startTransition] = useTransition();

  const [filters, setFilters] = useState<VoterFilters>(initialFilters);
  const [searchText, setSearchText] = useState(initialFilters.q);
  const [list, setList] = useState<VoterList>(initial);
  const [groups, setGroups] = useState<GroupList>(initialGroups);
  const [form, setForm] = useState<FormState | null>(null);
  const [deleting, setDeleting] = useState<Voter | null>(null);
  const [groupForm, setGroupForm] = useState<GroupFormState | null>(null);
  const [merging, setMerging] = useState<VoterGroup | null>(null);
  const [deletingGroup, setDeletingGroup] = useState<VoterGroup | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [lockedSeen, setLockedSeen] = useState(false);

  // The filters the shown list was read for, the newest filters, and the newest read (stale
  // answers are dropped).
  const loaded = useRef<VoterFilters>(initialFilters);
  const current = useRef<VoterFilters>(initialFilters);
  const ticket = useRef(0);
  const addButton = useRef<HTMLButtonElement>(null);

  current.current = filters;

  const rules = rulesOf(lockedSeen ? 'closed' : election.status);
  const showLocked = rules.locked;
  const pages = pageCount(list.total);

  function failure(caught: unknown) {
    const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

    if (error.code === 'election_voters_locked') {
      void locked();

      return;
    }

    setProblem(`${t('voters.loadFailed')} ${errorText(error, tIfAny)}`);
  }

  /** Reads the list for these filters; a page past the end moves to the last one. */
  async function load(wanted: VoterFilters) {
    const mine = ++ticket.current;

    try {
      const next = await fetchVoters(election.id, wanted);

      if (mine !== ticket.current) return;

      const last = pageCount(next.total);

      if (wanted.page > last) {
        setFilters({ ...wanted, page: last });

        return;
      }

      loaded.current = wanted;
      setList(next);
      setProblem(null);
    } catch (caught) {
      if (mine === ticket.current) failure(caught);
    }
  }

  /** The list for the current filters and the groups, read again after a change. */
  async function refresh() {
    const wanted = current.current;
    const mine = ++ticket.current;

    try {
      const [voters, found] = await Promise.all([
        fetchVoters(election.id, wanted),
        fetchGroups(election.id),
      ]);

      if (mine !== ticket.current) return;

      setGroups(found);

      const gone =
        wanted.group !== '' &&
        wanted.group !== 'none' &&
        !found.items.some((group) => group.id === wanted.group);
      const last = pageCount(voters.total);

      if (gone || wanted.page > last) {
        // The group is gone (merged, deleted) or the page has no more voters: the effect reads.
        setFilters({
          ...wanted,
          group: gone ? '' : wanted.group,
          page: Math.min(wanted.page, last),
        });

        return;
      }

      loaded.current = wanted;
      setList(voters);
      setProblem(null);
    } catch (caught) {
      if (mine === ticket.current) failure(caught);
    }
  }

  /** The election no longer lets voters change: the notice, the real list and status. */
  async function locked() {
    setForm(null);
    setDeleting(null);
    setGroupForm(null);
    setMerging(null);
    setDeletingGroup(null);
    setLockedSeen(true);
    startTransition(() => router.refresh());

    try {
      const [voters, found] = await Promise.all([
        fetchVoters(election.id, current.current),
        fetchGroups(election.id),
      ]);

      loaded.current = current.current;
      setList(voters);
      setGroups(found);
    } catch {
      // The notice stands; the page is read again with the refresh above.
    }
  }

  // A change of filters (a chip, a page, the search) reads the list and writes the address.
  useEffect(() => {
    if (sameFilters(filters, loaded.current)) return;

    window.history.replaceState(null, '', `${window.location.pathname}${queryOf(filters)}`);
    void load(filters);
    // `load` only reads what the render gave it and refs.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters]);

  // The search is applied when typing stops.
  useEffect(() => {
    const text = searchText.trim();

    if (text === current.current.q) return;

    const timer = window.setTimeout(() => {
      setFilters((now) => ({ ...now, q: text, page: 1 }));
    }, DEBOUNCE_MS);

    return () => window.clearTimeout(timer);
  }, [searchText]);

  const searching = filters.q !== '' || filters.group !== '';
  const total = groups.votersTotal;
  const selectedGroup = filters.group;

  function chooseGroup(group: string) {
    setFilters((now) => ({ ...now, group, page: 1 }));
  }

  function clearFilters() {
    setSearchText('');
    setFilters({ page: 1, q: '', group: '' });
  }

  function goTo(page: number) {
    setFilters((now) => ({ ...now, page }));
  }

  return (
    <div data-testid="voters-page" className="flex flex-col gap-4.5">
      <Link
        href={`/admin/elections/${election.id}`}
        data-testid="voters-back"
        className={cx(
          'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink',
          focusRing,
        )}
      >
        <Icon name="left" size={18} />
        {t('voters.back')}
      </Link>

      <section
        data-testid="voters-hero"
        data-status={election.status}
        aria-labelledby="voters-title"
        className="election-hero cover-sweep relative flex min-h-32 flex-wrap items-end gap-x-6 gap-y-4 rounded-xl px-4 py-5 text-surface shadow-2 md:px-7.5 [@media(max-height:820px)]:min-h-24 [@media(max-height:820px)]:py-3.5"
      >
        <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
          <Icon name={COVER_ICONS[election.status]} size={210} />
        </span>
        <div className="relative z-10 min-w-0 flex-[1_1_22rem]">
          <span
            data-testid="voters-badge"
            className={cx(
              'inline-flex min-h-7 items-center gap-2 rounded-full bg-surface/95 px-3.5 py-1 text-sm font-bold',
              BADGE_TEXT[election.status],
            )}
          >
            {election.status === 'open' ? (
              <LiveDot />
            ) : (
              <Icon name={STATUS_ICONS[election.status]} size={16} />
            )}
            <span data-status={election.status}>
              {election.status === 'draft'
                ? t('voters.badgeDraft')
                : t(`elections.status.${election.status}`)}
            </span>
          </span>
          <h2
            id="voters-title"
            data-testid="voters-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('voters.title')}
          </h2>
          <p
            data-testid="voters-election"
            className="line-clamp-2 text-md break-words text-surface"
          >
            {election.title}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span data-testid="voters-count" className={CHIP}>
              <Icon name="people" size={18} />
              {votersText(total, locale, t)}
            </span>
            <span data-testid="groups-count" className={CHIP}>
              <Icon name="list" size={18} />
              {groupsText(groups.items.length, locale, t)}
            </span>
            {groups.ungrouped > 0 ? (
              <span data-testid="ungrouped-count" className={CHIP}>
                <Icon name="user" size={18} />
                {t('voters.ungrouped', { count: groups.ungrouped })}
              </span>
            ) : null}
          </div>
        </div>

        {rules.write ? (
          <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
            <button
              ref={addButton}
              type="button"
              data-testid="voter-add"
              onClick={() => setForm({ mode: 'new' })}
              className={ADD}
            >
              <Icon name="plus" size={20} />
              {t('voters.add')}
            </button>
          </div>
        ) : null}
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="voters-notice">
          {problem}
        </Notice>
      ) : null}

      {showLocked ? (
        <Notice tone="info" data-testid="voters-locked-notice">
          {t('voters.locked')}
        </Notice>
      ) : null}

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <section
          data-testid="voters-zone"
          aria-label={t('voters.zone.label')}
          className="flex min-w-0 flex-1 flex-col gap-4 rounded-2xl border border-line bg-surface p-2.5 shadow-1 md:p-3 2xl:p-5"
        >
          {total > 0 ? (
            <div className="flex flex-col gap-3">
              <div className="max-w-xl">
                <Input
                  type="search"
                  label={t('voters.search')}
                  value={searchText}
                  onChange={(event) => setSearchText(event.target.value)}
                  maxLength={SEARCH_MAX}
                  autoComplete="off"
                  enterKeyHint="search"
                  data-testid="voters-search"
                />
              </div>
              <div
                role="group"
                aria-label={t('voters.filter.label')}
                className="flex flex-wrap gap-2"
              >
                <button
                  type="button"
                  data-testid="voters-filter-all"
                  aria-pressed={selectedGroup === ''}
                  onClick={() => chooseGroup('')}
                  className={cx(
                    FILTER,
                    selectedGroup === ''
                      ? 'border-primary bg-primary text-surface'
                      : 'border-line-strong bg-surface text-ink hover:bg-primary-soft',
                  )}
                >
                  {t('voters.filter.all')}
                  <b>{total}</b>
                </button>
                {groups.items.map((group, index) => (
                  <button
                    key={group.id}
                    type="button"
                    data-testid={`voters-filter-${index + 1}`}
                    aria-pressed={selectedGroup === group.id}
                    onClick={() => chooseGroup(group.id)}
                    className={cx(
                      FILTER,
                      selectedGroup === group.id
                        ? 'border-primary bg-primary text-surface'
                        : 'border-line-strong bg-surface text-ink hover:bg-primary-soft',
                    )}
                  >
                    <span className="min-w-0 truncate">{group.name}</span>
                    <b>{group.voters_count}</b>
                  </button>
                ))}
                {groups.ungrouped > 0 || selectedGroup === 'none' ? (
                  <button
                    type="button"
                    data-testid="voters-filter-none"
                    aria-pressed={selectedGroup === 'none'}
                    onClick={() => chooseGroup('none')}
                    className={cx(
                      FILTER,
                      selectedGroup === 'none'
                        ? 'border-primary bg-primary text-surface'
                        : 'border-line-strong bg-surface text-ink hover:bg-primary-soft',
                    )}
                  >
                    {t('voters.filter.none')}
                    <b>{groups.ungrouped}</b>
                  </button>
                ) : null}
              </div>
            </div>
          ) : null}

          {list.items.length === 0 ? (
            total === 0 ? (
              <div
                data-testid="voters-empty"
                className="flex flex-col items-center gap-3 rounded-lg border-2 border-dashed border-primary-line bg-surface-alt px-4 py-10 text-center"
              >
                <span
                  aria-hidden="true"
                  className="flex size-16 items-center justify-center rounded-full bg-primary text-surface shadow-button"
                >
                  <Icon name="people" size={30} />
                </span>
                <h3 className="font-display text-lg font-extrabold text-ink">
                  {t('voters.empty.title')}
                </h3>
                <p className="max-w-md text-base text-ink-soft">
                  {t(rules.write ? 'voters.empty.text' : 'voters.empty.locked')}
                </p>
                {rules.write ? (
                  <button
                    type="button"
                    data-testid="voters-empty-add"
                    onClick={() => setForm({ mode: 'new' })}
                    className="ui-control lift-sm inline-flex min-h-11 items-center gap-2 rounded-full border border-primary bg-primary pr-5 pl-4 text-base font-semibold text-surface shadow-button hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <Icon name="plus" size={18} />
                    {t('voters.add')}
                  </button>
                ) : null}
              </div>
            ) : (
              <div
                data-testid="voters-no-match"
                role="status"
                className="flex flex-col items-center gap-3 rounded-lg bg-surface-alt px-4 py-10 text-center"
              >
                <p className="text-base text-ink-soft">{t('voters.noMatch.text')}</p>
                {searching ? (
                  <button
                    type="button"
                    data-testid="voters-reset"
                    onClick={clearFilters}
                    className={cx(
                      'ui-control inline-flex min-h-11 items-center rounded-full px-4 text-base font-bold text-primary-hover hover:bg-primary-soft',
                      focusRing,
                    )}
                  >
                    {t('voters.noMatch.reset')}
                  </button>
                ) : null}
              </div>
            )
          ) : (
            <RevealList
              aria-label={t('voters.zone.title')}
              data-testid="voters-list"
              className="grid grid-cols-1 gap-3 md:grid-cols-2 min-[2300px]:grid-cols-3"
            >
              {list.items.map((voter, index) => (
                <VoterCard
                  key={voter.id}
                  voter={voter}
                  n={index + 1}
                  canEdit={rules.write}
                  canDelete={rules.remove}
                  onEdit={(chosen) => setForm({ mode: 'edit', voter: chosen })}
                  onDelete={setDeleting}
                />
              ))}
            </RevealList>
          )}

          {list.total > 0 ? (
            <nav
              data-testid="voters-pager"
              aria-label={t('voters.pager.label')}
              className="flex flex-col items-center justify-between gap-3 md:flex-row"
            >
              <p data-testid="voters-range" className="text-base text-ink-soft">
                {rangeText(filters.page, list.total, locale, t)}
              </p>
              {pages > 1 ? (
                <div className="flex flex-wrap items-center justify-center gap-1.5">
                  <button
                    type="button"
                    data-testid="voters-prev"
                    aria-label={t('voters.pager.prev')}
                    disabled={filters.page <= 1}
                    onClick={() => goTo(filters.page - 1)}
                    className={cx(PAGE_BUTTON, 'border-line-strong bg-surface text-ink')}
                  >
                    <Icon name="left" size={18} />
                  </button>
                  {pageItems(filters.page, pages).map((item, index) =>
                    item === 'gap' ? (
                      <span key={`gap-${index}`} aria-hidden="true" className="px-1 text-ink-soft">
                        {'\u2026'}
                      </span>
                    ) : (
                      <button
                        key={item}
                        type="button"
                        data-testid={`voters-page-${item}`}
                        aria-label={t('voters.pager.page', { page: item })}
                        aria-current={item === filters.page ? 'page' : undefined}
                        onClick={() => goTo(item)}
                        className={cx(
                          PAGE_BUTTON,
                          item === filters.page
                            ? 'border-primary bg-primary text-surface'
                            : 'border-line-strong bg-surface text-ink hover:bg-primary-soft',
                        )}
                      >
                        {item}
                      </button>
                    ),
                  )}
                  <button
                    type="button"
                    data-testid="voters-next"
                    aria-label={t('voters.pager.next')}
                    disabled={filters.page >= pages}
                    onClick={() => goTo(filters.page + 1)}
                    className={cx(PAGE_BUTTON, 'border-line-strong bg-surface text-ink')}
                  >
                    <Icon name="right" size={18} />
                  </button>
                </div>
              ) : null}
            </nav>
          ) : null}
        </section>

        <aside
          data-testid="voters-rail"
          aria-label={t('voters.rail.label')}
          className="flex w-full flex-wrap gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col"
        >
          <GroupsCard
            groups={groups.items}
            selected={selectedGroup}
            manage={rules.groups}
            onFilter={(group) => chooseGroup(selectedGroup === group.id ? '' : group.id)}
            onNew={() => setGroupForm({ mode: 'new' })}
            onRename={(group) => setGroupForm({ mode: 'edit', group })}
            onMerge={setMerging}
            onDelete={setDeletingGroup}
          />
          <Panel
            tone="steps"
            icon="qr"
            title={t('voters.codes.title')}
            id="voters-codes-title"
            testId="codes-card"
            className="flex-[1_1_20rem] 2xl:flex-none"
            aside={
              <span className={PANEL_ASIDE}>
                <Icon name="lock" size={14} />
              </span>
            }
          >
            <p className="flex items-start gap-2 text-base text-ink-soft">
              <span className="mt-0.5">
                <Icon name="lock" size={18} />
              </span>
              {t('voters.codes.text')}
            </p>
          </Panel>
        </aside>
      </div>

      {rules.write && form ? (
        <VoterModal
          key={form.mode === 'edit' ? form.voter.id : 'new'}
          voter={form.mode === 'edit' ? form.voter : null}
          election={election.id}
          groups={groups.items}
          onSaved={() => void refresh()}
          onLocked={() => void locked()}
          onClose={() => setForm(null)}
        />
      ) : null}

      {rules.remove && deleting ? (
        <DeleteVoterDialog
          voter={deleting}
          onLocked={() => void locked()}
          onCancel={() => setDeleting(null)}
          onDeleted={() => {
            setDeleting(null);
            void refresh().then(() => addButton.current?.focus());
          }}
        />
      ) : null}

      {rules.groups && groupForm ? (
        <GroupModal
          key={groupForm.mode === 'edit' ? groupForm.group.id : 'new'}
          election={election.id}
          group={groupForm.mode === 'edit' ? groupForm.group : null}
          onSaved={() => void refresh()}
          onLocked={() => void locked()}
          onClose={() => setGroupForm(null)}
        />
      ) : null}

      {rules.groups && merging ? (
        <MergeGroupDialog
          group={merging}
          others={groups.items.filter((group) => group.id !== merging.id)}
          onLocked={() => void locked()}
          onCancel={() => setMerging(null)}
          onMerged={() => {
            setMerging(null);
            void refresh();
          }}
        />
      ) : null}

      {rules.groups && deletingGroup ? (
        <DeleteGroupDialog
          group={deletingGroup}
          onLocked={() => void locked()}
          onCancel={() => setDeletingGroup(null)}
          onDeleted={() => {
            setDeletingGroup(null);
            void refresh();
          }}
        />
      ) : null}
    </div>
  );
}
