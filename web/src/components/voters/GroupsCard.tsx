'use client';

import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { PANEL_ASIDE, Panel } from '@/components/elections/Panel';
import { cx } from '@/components/ui/cx';
import type { VoterGroup } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import { votersText } from './voterText';

/** A round control of a row: 44 px on a phone. */
const ROUND =
  'ui-control inline-flex shrink-0 items-center justify-center rounded-full size-11 md:size-9 bg-canvas text-ink-soft hover:bg-primary-soft disabled:opacity-40 disabled:hover:bg-canvas ' +
  focusRing;

/**
 * The groups card of the rail: a row per group in the order the API gives (the name, which
 * shows that group's voters, the voter count, rename, merge and delete) and the button of the
 * group modal. `n` in the test ids is the 1-based row. Delete is disabled while the group has
 * voters; merge needs another group. With `manage` off the rows are shown alone.
 */
export function GroupsCard({
  groups,
  selected,
  manage,
  onFilter,
  onNew,
  onRename,
  onMerge,
  onDelete,
}: {
  groups: VoterGroup[];
  /** The group the list is filtered on, if any (a UUID). */
  selected: string;
  manage: boolean;
  onFilter: (group: VoterGroup) => void;
  onNew: () => void;
  onRename: (group: VoterGroup) => void;
  onMerge: (group: VoterGroup) => void;
  onDelete: (group: VoterGroup) => void;
}) {
  const { t, locale } = useI18n();

  return (
    <Panel
      tone="info"
      icon="people"
      title={t('groups.title')}
      id="groups-title"
      testId="groups-card"
      className="flex-[1_1_20rem] 2xl:flex-none"
      bodyClassName="gap-2"
      aside={
        <span data-testid="groups-card-count" className={PANEL_ASIDE}>
          {groups.length}
        </span>
      }
    >
      {groups.length === 0 ? (
        <p data-testid="groups-empty" className="text-base text-ink-soft">
          {t('groups.empty')}
        </p>
      ) : (
        <ul aria-label={t('groups.title')} className="flex flex-col gap-1.5">
          {groups.map((group, index) => {
            const n = index + 1;

            return (
              <li
                key={group.id}
                data-testid={`group-row-${n}`}
                className="flex min-w-0 items-center gap-2 rounded-md bg-surface-alt p-2"
              >
                <span
                  aria-hidden="true"
                  className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
                >
                  <Icon name="people" size={20} />
                </span>
                <span className="flex min-w-0 flex-1 flex-col items-start">
                  <button
                    type="button"
                    data-testid={`group-name-${n}`}
                    aria-pressed={selected === group.id}
                    title={t('groups.row.filter', { name: group.name })}
                    onClick={() => onFilter(group)}
                    className={cx(
                      'line-clamp-2 max-w-full rounded text-left text-md leading-tight font-medium break-words hover:text-primary-hover hover:underline',
                      selected === group.id ? 'text-primary-hover underline' : 'text-ink',
                      focusRing,
                    )}
                  >
                    {group.name}
                  </button>
                  <span data-testid={`group-count-${n}`} className="text-sm text-ink-soft">
                    {votersText(group.voters_count, locale, t)}
                  </span>
                </span>
                {manage ? (
                  <>
                    <button
                      type="button"
                      aria-label={t('groups.row.rename', { name: group.name })}
                      data-testid={`group-rename-${n}`}
                      onClick={() => onRename(group)}
                      className={ROUND}
                    >
                      <Icon name="edit" size={18} />
                    </button>
                    <button
                      type="button"
                      aria-label={t('groups.row.merge', { name: group.name })}
                      data-testid={`group-merge-${n}`}
                      disabled={groups.length < 2}
                      onClick={() => onMerge(group)}
                      className={ROUND}
                    >
                      <Icon name="shuffle" size={18} />
                    </button>
                    <button
                      type="button"
                      aria-label={t('groups.row.delete', { name: group.name })}
                      data-testid={`group-delete-${n}`}
                      disabled={group.voters_count > 0}
                      onClick={() => onDelete(group)}
                      className={cx(ROUND, 'text-danger hover:bg-warm-softer')}
                    >
                      <Icon name="trash" size={18} />
                    </button>
                  </>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}

      {manage ? (
        <button
          type="button"
          data-testid="group-new"
          onClick={onNew}
          className={cx(
            'lift mt-1 flex min-h-11 w-full items-center justify-center gap-2 rounded-full border-2 border-dashed border-primary-line bg-surface-alt px-4 text-md font-bold text-status-scheduled hover:bg-primary-soft',
            focusRing,
          )}
        >
          <Icon name="plus" size={18} />
          {t('groups.new')}
        </button>
      ) : null}

      {groups.length > 0 ? (
        <p className="flex items-start gap-2 text-sm text-ink-soft">
          <span className="mt-0.5">
            <Icon name="warn" size={16} />
          </span>
          {t('groups.note')}
        </p>
      ) : null}
    </Panel>
  );
}
