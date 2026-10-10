'use client';

import type { PointerEvent } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { cx } from '@/components/ui/cx';
import type { Candidate } from '@/lib/api/candidates';
import { sexOf } from '@/lib/api/candidates';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { CandidateAvatar } from './CandidateAvatar';
import { fullName } from './candidateForm';

/** A round control of a row: 44 px on a phone. */
const ROUND =
  'ui-control inline-flex shrink-0 items-center justify-center rounded-full size-11 md:size-8 bg-canvas text-ink-soft hover:bg-primary-soft disabled:opacity-40 ';

/** The party of a candidate: its colour dot and name, or "Indépendant". */
export function PartyTag({
  party,
  textClass = 'text-sm font-medium text-ink-soft',
}: {
  party: Party | null;
  /** The size, weight and colour of the text. */
  textClass?: string;
}) {
  const { t } = useI18n();

  return (
    <span className={cx('inline-flex min-w-0 items-center gap-1.5', textClass)}>
      <i
        aria-hidden="true"
        style={party ? { backgroundColor: party.colour } : undefined}
        className={cx('size-2.5 shrink-0 rounded-full', party ? null : 'bg-line-strong')}
      />
      <span className="truncate">{party ? party.name : t('candidates.independent')}</span>
    </span>
  );
}

/**
 * One candidate in a ballot card: the grip, the avatar by sex, the name, the party and, when the
 * election can change, the arrows, edit and delete. `b` is the card's place and `c` the row's
 * (both from 1), in the test ids. In a narrow card the buttons go under the name.
 */
export function CandidateRow({
  candidate,
  party,
  b,
  c,
  total,
  editable,
  dragging,
  over,
  onMove,
  onEdit,
  onDelete,
  onGripDown,
  onGripMove,
  onGripUp,
}: {
  candidate: Candidate;
  party: Party | null;
  b: number;
  c: number;
  total: number;
  editable: boolean;
  dragging: boolean;
  over: boolean;
  onMove: (candidate: Candidate, step: -1 | 1) => void;
  onEdit: (candidate: Candidate) => void;
  onDelete: (candidate: Candidate) => void;
  onGripDown: (event: PointerEvent<HTMLElement>, candidate: Candidate) => void;
  onGripMove: (event: PointerEvent<HTMLElement>) => void;
  onGripUp: (event: PointerEvent<HTMLElement>, cancelled: boolean) => void;
}) {
  const { t } = useI18n();
  const name = fullName(candidate);

  return (
    <li
      data-testid={`candidate-row-${b}-${c}`}
      data-candidate-id={candidate.id}
      data-candidate-ballot={candidate.ballot}
      data-dragging={dragging || undefined}
      data-over={over || undefined}
      className={cx(
        'flex flex-wrap items-center gap-x-2.5 rounded-lg border border-transparent py-1.5 pr-1.5 pl-0.5 transition-colors hover:border-line hover:bg-surface-alt',
        dragging && 'opacity-60',
        over && 'border-primary-line bg-primary-soft',
      )}
    >
      {editable ? (
        <span
          aria-hidden="true"
          data-testid={`candidate-grip-${b}-${c}`}
          onPointerDown={(event) => onGripDown(event, candidate)}
          onPointerMove={onGripMove}
          onPointerUp={(event) => onGripUp(event, false)}
          onPointerCancel={(event) => onGripUp(event, true)}
          className="flex h-10 w-5 shrink-0 cursor-grab touch-none items-center justify-center text-ink-soft select-none active:cursor-grabbing"
        >
          <Icon name="grip" size={18} />
        </span>
      ) : null}
      <CandidateAvatar
        sex={sexOf(candidate)}
        size="size-10"
        iconSize={22}
        testId={`candidate-avatar-${b}-${c}`}
      />
      <span className="flex min-w-0 grow basis-[calc(100%-5rem)] flex-col gap-0.5 @[23rem]:basis-0">
        <b
          data-testid={`candidate-name-${b}-${c}`}
          className="line-clamp-2 text-md leading-tight font-medium break-words text-ink"
        >
          {name}
        </b>
        <span data-testid={`candidate-party-${b}-${c}`} className="flex min-w-0">
          <PartyTag party={party} />
        </span>
      </span>
      {editable ? (
        <span className="ml-auto flex shrink-0 items-center gap-1">
          <button
            type="button"
            disabled={c === 1}
            aria-label={t('candidates.row.up', { name })}
            data-testid={`candidate-up-${b}-${c}`}
            onClick={() => onMove(candidate, -1)}
            className={cx(ROUND, focusRing)}
          >
            <Icon name="up" size={18} />
          </button>
          <button
            type="button"
            disabled={c === total}
            aria-label={t('candidates.row.down', { name })}
            data-testid={`candidate-down-${b}-${c}`}
            onClick={() => onMove(candidate, 1)}
            className={cx(ROUND, focusRing)}
          >
            <Icon name="down" size={18} />
          </button>
          <button
            type="button"
            aria-label={t('candidates.row.edit', { name })}
            data-testid={`candidate-edit-${b}-${c}`}
            onClick={() => onEdit(candidate)}
            className={cx(ROUND, focusRing)}
          >
            <Icon name="edit" size={17} />
          </button>
          <button
            type="button"
            aria-label={t('candidates.row.delete', { name })}
            data-testid={`candidate-delete-${b}-${c}`}
            onClick={() => onDelete(candidate)}
            className={cx(ROUND, 'text-danger hover:bg-warm-softer', focusRing)}
          >
            <Icon name="trash" size={17} />
          </button>
        </span>
      ) : null}
    </li>
  );
}
