'use client';

import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { PANEL_ASIDE, Panel } from '@/components/elections/Panel';
import { cx } from '@/components/ui/cx';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { swatchText } from './partyForm';
import { candidatesText } from './partyText';

/** A round control of a row: 44 px on a phone. */
const ROUND =
  'ui-control inline-flex shrink-0 items-center justify-center rounded-full size-11 md:size-9 bg-canvas text-ink-soft hover:bg-primary-soft ' +
  focusRing;

/**
 * The parties card of the rail: a row per party in the order the API gives (a colour swatch with
 * the acronym or the first two letters, the name, the acronym, the candidate count, edit and
 * delete), and the button of the party modal. `n` in the test ids is the 1-based row. Only a
 * draft can change: any other election shows the rows alone.
 */
export function PartiesCard({
  parties,
  editable,
  onNew,
  onEdit,
  onDelete,
}: {
  parties: Party[];
  editable: boolean;
  onNew: () => void;
  onEdit: (party: Party) => void;
  onDelete: (party: Party) => void;
}) {
  const { t, locale } = useI18n();

  return (
    <Panel
      tone="info"
      icon="party"
      title={t('parties.title')}
      id="parties-title"
      testId="parties-card"
      className="flex-[1_1_20rem] 2xl:flex-none"
      bodyClassName="gap-2"
      aside={
        <span data-testid="parties-card-count" className={PANEL_ASIDE}>
          {parties.length}
        </span>
      }
    >
      {parties.length === 0 ? (
        <p data-testid="parties-empty" className="text-base text-ink-soft">
          {t('parties.empty')}
        </p>
      ) : (
        <ul aria-label={t('parties.title')} className="flex flex-col gap-1.5">
          {parties.map((party, index) => {
            const n = index + 1;

            return (
              <li
                key={party.id}
                data-testid={`party-row-${n}`}
                className="flex min-w-0 items-center gap-3 rounded-md bg-surface-alt p-2"
              >
                <span
                  aria-hidden="true"
                  data-testid={`party-swatch-${n}`}
                  style={party.logo ? undefined : { backgroundColor: party.colour }}
                  className={cx(
                    'flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md font-display text-sm font-extrabold',
                    party.logo ? 'border border-line bg-surface p-0.5' : 'px-1 text-surface',
                  )}
                >
                  {party.logo ? (
                    // An already optimised picture of the media disk, shown inside its box.
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={party.logo.sm}
                      alt=""
                      data-testid={`party-logo-${n}`}
                      className="size-full object-contain"
                    />
                  ) : (
                    <span className="truncate">{swatchText(party)}</span>
                  )}
                </span>
                <span className="flex min-w-0 flex-1 flex-col">
                  <b
                    data-testid={`party-name-${n}`}
                    className="line-clamp-2 text-md leading-tight font-medium break-words text-ink"
                  >
                    {party.name}
                  </b>
                  <span className="flex flex-wrap items-center gap-x-2 text-sm text-ink-soft">
                    {party.acronym ? (
                      <span data-testid={`party-acronym-${n}`} className="font-bold break-all">
                        {party.acronym}
                      </span>
                    ) : null}
                    <span data-testid={`party-count-${n}`}>
                      {candidatesText(party.candidates_count, locale, t)}
                    </span>
                  </span>
                </span>
                {editable ? (
                  <>
                    <button
                      type="button"
                      aria-label={t('parties.row.edit', { name: party.name })}
                      data-testid={`party-edit-${n}`}
                      onClick={() => onEdit(party)}
                      className={ROUND}
                    >
                      <Icon name="edit" size={18} />
                    </button>
                    <button
                      type="button"
                      aria-label={t('parties.row.delete', { name: party.name })}
                      data-testid={`party-delete-${n}`}
                      onClick={() => onDelete(party)}
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

      {editable ? (
        <button
          type="button"
          data-testid="party-new"
          onClick={onNew}
          className={cx(
            'lift mt-1 flex min-h-11 w-full items-center justify-center gap-2 rounded-full border-2 border-dashed border-primary-line bg-surface-alt px-4 text-md font-bold text-status-scheduled hover:bg-primary-soft',
            focusRing,
          )}
        >
          <Icon name="plus" size={18} />
          {t('parties.new')}
        </button>
      ) : null}
    </Panel>
  );
}
