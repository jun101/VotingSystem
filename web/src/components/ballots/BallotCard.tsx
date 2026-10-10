'use client';

import type { PointerEvent } from 'react';
import { Icon, type IconName } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { cx } from '@/components/ui/cx';
import type { Ballot } from '@/lib/api/ballots';
import type { Candidate } from '@/lib/api/candidates';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { seatsText } from './ballotText';
import { candidateCountText } from './candidateText';
import { CandidateRow } from './CandidateRow';

/** The round icon of the header, by tone: the four of the mockup. */
const TONE_ICONS: readonly IconName[] = ['ballot', 'edit', 'flag', 'people'];

/** A round control of the header band (glass) or of the footer (soft); 44 px on a phone. */
const ROUND =
  'ui-control inline-flex shrink-0 items-center justify-center rounded-full size-11 md:size-9 ';
const HEAD_BUTTON =
  ROUND +
  'bg-glass text-surface hover:bg-glass-line focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-surface disabled:opacity-40';
const FOOT_BUTTON = ROUND + 'bg-canvas text-ink-soft hover:bg-primary-soft ' + focusRing;

/** What the page does with the candidates of a card, and the row being dragged in it. */
export type CandidateActions = {
  /** The candidate being dragged by its grip in this ballot, and the one under the pointer. */
  drag: { id: string; over: string } | null;
  onAdd: (ballot: Ballot) => void;
  onMove: (ballot: Ballot, candidate: Candidate, step: -1 | 1) => void;
  onEdit: (candidate: Candidate) => void;
  onDelete: (candidate: Candidate) => void;
  onGripDown: (event: PointerEvent<HTMLElement>, ballot: Ballot, candidate: Candidate) => void;
  onGripMove: (event: PointerEvent<HTMLElement>) => void;
  onGripUp: (event: PointerEvent<HTMLElement>, cancelled: boolean) => void;
};

/**
 * One ballot as a card: a coloured header (the grip, the title that wraps to two lines, the up
 * and down buttons), the tags (seats, blank vote, candidates), the candidate rows (or the
 * invitation when there are none), the notice of a single candidate and the footer (add a
 * candidate, edit, delete). `n` is the 1-based position, in the test ids. The grip is the pointer
 * path of the reorder and the buttons are the keyboard and phone path; both are only there when
 * the election can still change.
 */
export function BallotCard({
  ballot,
  n,
  total,
  editable,
  parties,
  candidates: actions,
  dragging,
  over,
  onMove,
  onEdit,
  onDelete,
  onGripDown,
  onGripMove,
  onGripUp,
}: {
  ballot: Ballot;
  n: number;
  total: number;
  editable: boolean;
  parties: readonly Party[];
  candidates: CandidateActions;
  dragging: boolean;
  over: boolean;
  onMove: (ballot: Ballot, step: -1 | 1) => void;
  onEdit: (ballot: Ballot) => void;
  onDelete: (ballot: Ballot) => void;
  onGripDown: (event: PointerEvent<HTMLElement>, ballot: Ballot) => void;
  onGripMove: (event: PointerEvent<HTMLElement>) => void;
  onGripUp: (event: PointerEvent<HTMLElement>, cancelled: boolean) => void;
}) {
  const { t, locale } = useI18n();
  const tone = (n - 1) % 4;

  return (
    <li
      data-testid={`ballot-card-${n}`}
      data-ballot-id={ballot.id}
      data-dragging={dragging || undefined}
      data-over={over || undefined}
      className={cx(
        'lift @container flex min-w-0 flex-col overflow-hidden rounded-lg border border-line bg-surface shadow-2',
        dragging && 'opacity-60',
        over && 'ring-4 ring-primary-line',
      )}
    >
      <div
        data-tone={tone}
        className="ballot-head relative flex h-16 shrink-0 items-center gap-2.5 overflow-hidden pr-3 pl-3 @[22rem]:gap-3 @[22rem]:pl-4"
      >
        {editable ? (
          <span
            aria-hidden="true"
            data-testid={`ballot-grip-${n}`}
            onPointerDown={(event) => onGripDown(event, ballot)}
            onPointerMove={onGripMove}
            onPointerUp={(event) => onGripUp(event, false)}
            onPointerCancel={(event) => onGripUp(event, true)}
            className="flex h-11 w-6 shrink-0 cursor-grab touch-none items-center justify-center text-surface/85 select-none active:cursor-grabbing"
          >
            <Icon name="grip" size={20} />
          </span>
        ) : null}
        <span
          aria-hidden="true"
          className="hidden size-9.5 shrink-0 items-center justify-center rounded-full bg-glass-line @[22rem]:flex"
        >
          <Icon name={TONE_ICONS[tone]!} size={21} />
        </span>
        <h3
          data-testid={`ballot-title-${n}`}
          className="line-clamp-2 min-w-0 flex-1 font-display text-lg leading-tight font-extrabold break-words"
        >
          {ballot.title}
        </h3>
        {editable ? (
          <>
            <button
              type="button"
              disabled={n === 1}
              aria-label={t('ballots.card.up', { title: ballot.title })}
              data-testid={`ballot-up-${n}`}
              onClick={() => onMove(ballot, -1)}
              className={HEAD_BUTTON}
            >
              <Icon name="up" size={20} />
            </button>
            <button
              type="button"
              disabled={n === total}
              aria-label={t('ballots.card.down', { title: ballot.title })}
              data-testid={`ballot-down-${n}`}
              onClick={() => onMove(ballot, 1)}
              className={HEAD_BUTTON}
            >
              <Icon name="down" size={20} />
            </button>
          </>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col gap-3 px-5 pt-4 pb-4">
        <div className="flex flex-wrap gap-2">
          <span
            data-testid={`ballot-seats-${n}`}
            className="inline-flex h-7 items-center gap-1.5 rounded-full bg-canvas pr-3 pl-2 text-sm font-medium text-ink-2"
          >
            <span className="text-primary">
              <Icon name="seat" size={16} />
            </span>
            {seatsText(ballot.seats, locale, t)}
          </span>
          <span
            data-testid={`ballot-blank-${n}`}
            className="inline-flex h-7 items-center gap-1.5 rounded-full bg-canvas pr-3 pl-2 text-sm font-medium text-ink-2"
          >
            <span className="text-primary">
              <Icon name="blank" size={16} />
            </span>
            {t(ballot.allow_blank ? 'ballots.blank' : 'ballots.noBlank')}
          </span>
          <span
            data-testid={`ballot-candidates-${n}`}
            className="inline-flex h-7 items-center gap-1.5 rounded-full bg-canvas pr-3 pl-2 text-sm font-medium text-ink-2"
          >
            <span className="text-primary">
              <Icon name="people" size={16} />
            </span>
            {candidateCountText(ballot.candidates.length, locale, t)}
          </span>
        </div>

        {ballot.description ? (
          <p className="line-clamp-3 text-base break-words whitespace-pre-line text-ink-soft">
            {ballot.description}
          </p>
        ) : null}

        {ballot.candidates.length === 0 ? (
          <div
            data-testid={`ballot-empty-${n}`}
            className="flex flex-col items-center gap-1.5 px-1 py-1 text-center text-base text-ink-soft"
          >
            <span
              aria-hidden="true"
              className="float-art flex size-13 items-center justify-center rounded-full bg-status-published-soft text-status-published"
            >
              <Icon name="user" size={28} />
            </span>
            <b className="text-md font-medium text-ink">{t('ballots.card.noCandidates')}</b>
            {editable ? <span>{t('ballots.card.noCandidatesHint')}</span> : null}
          </div>
        ) : (
          <ul
            aria-label={t('ballots.card.candidatesLabel', { title: ballot.title })}
            className="flex flex-col gap-1"
          >
            {ballot.candidates.map((candidate, index) => (
              <CandidateRow
                key={candidate.id}
                candidate={candidate}
                party={parties.find((party) => party.id === candidate.party) ?? null}
                b={n}
                c={index + 1}
                total={ballot.candidates.length}
                editable={editable}
                dragging={actions.drag?.id === candidate.id}
                over={
                  actions.drag !== null &&
                  actions.drag.id !== candidate.id &&
                  actions.drag.over === candidate.id
                }
                onMove={(chosen, step) => actions.onMove(ballot, chosen, step)}
                onEdit={actions.onEdit}
                onDelete={actions.onDelete}
                onGripDown={(event, chosen) => actions.onGripDown(event, ballot, chosen)}
                onGripMove={actions.onGripMove}
                onGripUp={actions.onGripUp}
              />
            ))}
          </ul>
        )}

        {ballot.candidates.length === 1 ? (
          <p
            data-testid={`ballot-warning-${n}`}
            className="flex items-start gap-2.5 rounded-lg bg-warm-softer px-3 py-2.5 text-base text-warm-ink"
          >
            <span className="mt-0.5 shrink-0 text-warm">
              <Icon name="warn" size={20} />
            </span>
            {t('ballots.card.oneCandidate')}
          </p>
        ) : null}

        {editable ? (
          <div className="mt-auto flex flex-wrap items-center gap-2 pt-1">
            <button
              type="button"
              aria-label={t('ballots.card.addCandidateTo', { title: ballot.title })}
              data-testid={`candidate-add-${n}`}
              data-candidate-add=""
              onClick={() => actions.onAdd(ballot)}
              className={cx(
                'ui-control inline-flex min-h-11 items-center gap-2 rounded-full bg-primary-soft pr-4.5 pl-3 text-base font-medium text-status-scheduled hover:bg-primary-line md:min-h-10',
                focusRing,
              )}
            >
              <Icon name="plus" size={18} />
              {t('ballots.card.addCandidate')}
            </button>
            <span className="ml-auto flex items-center gap-2">
              <button
                type="button"
                aria-label={t('ballots.card.edit', { title: ballot.title })}
                data-testid={`ballot-edit-${n}`}
                onClick={() => onEdit(ballot)}
                className={FOOT_BUTTON}
              >
                <Icon name="edit" size={18} />
              </button>
              <button
                type="button"
                aria-label={t('ballots.card.delete', { title: ballot.title })}
                data-testid={`ballot-delete-${n}`}
                onClick={() => onDelete(ballot)}
                className={cx(FOOT_BUTTON, 'text-danger hover:bg-warm-softer')}
              >
                <Icon name="trash" size={18} />
              </button>
            </span>
          </div>
        ) : null}
      </div>
    </li>
  );
}
