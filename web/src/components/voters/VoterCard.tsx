'use client';

import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { Voter } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import { initialsOf, toneOf } from './voterText';

/** A round control of a card: 44 px on a phone. */
const ROUND =
  'ui-control inline-flex shrink-0 items-center justify-center rounded-full size-11 md:size-9 bg-canvas text-ink-soft hover:bg-primary-soft ' +
  focusRing;

/** The colours of the initials, one of them per voter by name (tokens only). */
const AVATAR_TONES = [
  'bg-primary-soft text-primary-hover',
  'bg-teal-soft text-teal-ink',
  'bg-warm-soft text-warm-ink',
  'bg-status-published-soft text-status-published',
] as const;

/** The phone glyph (the icon set has none): a handset as one stroke. */
const PHONE_PATH =
  'M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z';

/**
 * One voter as a compact card: the initials, the name, the group tag, the identifier and the
 * contact icons, then edit and delete when the page allows them. `n` in the test ids is the
 * 1-based place on the page. Every card has the same height: long texts are cut, never wrapped.
 */
export function VoterCard({
  voter,
  n,
  canEdit,
  canDelete,
  onEdit,
  onDelete,
}: {
  voter: Voter;
  n: number;
  canEdit: boolean;
  canDelete: boolean;
  onEdit: (voter: Voter) => void;
  onDelete: (voter: Voter) => void;
}) {
  const { t } = useI18n();

  return (
    <li
      data-testid={`voter-card-${n}`}
      className="lift flex h-20 min-w-0 items-center gap-3 rounded-lg border border-line bg-surface p-3 shadow-1"
    >
      <span
        aria-hidden="true"
        className={cx(
          'flex size-12 shrink-0 items-center justify-center rounded-full font-display text-md font-extrabold',
          AVATAR_TONES[toneOf(voter.full_name, AVATAR_TONES.length)],
        )}
      >
        {initialsOf(voter.full_name)}
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <b
          data-testid={`voter-name-${n}`}
          title={voter.full_name}
          className="truncate text-md leading-tight font-medium text-ink"
        >
          {voter.full_name}
        </b>
        <span className="flex min-w-0 items-center gap-2 text-sm text-ink-soft">
          <span
            data-testid={`voter-group-${n}`}
            className={cx(
              'min-w-0 shrink truncate rounded-full px-2.5 py-0.5 text-xs font-bold',
              voter.group ? 'bg-primary-soft text-primary-hover' : 'bg-canvas text-ink-soft',
            )}
          >
            {voter.group ? voter.group.name : t('voters.card.noGroup')}
          </span>
          {voter.identifier ? (
            <span
              data-testid={`voter-identifier-${n}`}
              className="min-w-0 truncate font-medium"
              title={voter.identifier}
            >
              {voter.identifier}
            </span>
          ) : null}
          {voter.email ? (
            <span
              role="img"
              data-testid={`voter-email-${n}`}
              aria-label={t('voters.card.email', { value: voter.email })}
              title={voter.email}
              className="shrink-0"
            >
              <Icon name="mail" size={16} />
            </span>
          ) : null}
          {voter.phone ? (
            <span
              role="img"
              data-testid={`voter-phone-${n}`}
              aria-label={t('voters.card.phone', { value: voter.phone })}
              title={voter.phone}
              className="shrink-0"
            >
              <Icon path={PHONE_PATH} size={16} />
            </span>
          ) : null}
        </span>
      </span>
      {canEdit ? (
        <button
          type="button"
          aria-label={t('voters.card.edit', { name: voter.full_name })}
          data-testid={`voter-edit-${n}`}
          onClick={() => onEdit(voter)}
          className={ROUND}
        >
          <Icon name="edit" size={18} />
        </button>
      ) : null}
      {canDelete ? (
        <button
          type="button"
          aria-label={t('voters.card.delete', { name: voter.full_name })}
          data-testid={`voter-delete-${n}`}
          onClick={() => onDelete(voter)}
          className={cx(ROUND, 'text-danger hover:bg-warm-softer')}
        >
          <Icon name="trash" size={18} />
        </button>
      ) : null}
    </li>
  );
}
