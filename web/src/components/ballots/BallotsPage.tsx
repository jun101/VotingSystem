'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  useEffect,
  useRef,
  useState,
  useTransition,
  type PointerEvent as ReactPointerEvent,
} from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { RevealList } from '@/components/elections/RevealList';
import { STATUS_ICONS, COVER_ICONS } from '@/components/elections/statusIcon';
import { LiveDot } from '@/components/motion';
import { Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import type { Ballot } from '@/lib/api/ballots';
import { fetchBallots, fetchParties, reorderBallots, reorderCandidates } from '@/lib/api/browser';
import type { Candidate } from '@/lib/api/candidates';
import type { Party } from '@/lib/api/parties';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { BallotCard, type CandidateActions } from './BallotCard';
import { BallotForm } from './BallotForm';
import { BallotsRail } from './BallotsRail';
import { CandidateModal } from './CandidateModal';
import { DeleteBallotDialog } from './DeleteBallotDialog';
import { DeleteCandidateDialog } from './DeleteCandidateDialog';
import { DeletePartyDialog } from './DeletePartyDialog';
import { PartyModal } from './PartyModal';
import { dropOn, mergeOrder, moveBy, orderOf, sameOrder } from './ballotMove';
import { countText } from './ballotText';
import { candidateCountText } from './candidateText';
import {
  candidateTotal,
  checksOf,
  detachParty,
  mergeCandidates,
  partyCounts,
  placeCandidate,
  removeCandidate,
  withCandidateOrder,
  withCandidates,
} from './candidateList';
import { partiesText } from './partyText';

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

/** The coral main action on the header band: its icon sits beside its text, on every screen. */
const ADD =
  'ui-control lift-sm inline-flex h-12 items-center justify-center gap-2 rounded-full border border-accent bg-accent pr-5.5 pl-4 text-md font-bold text-deep shadow-button max-md:w-full ' +
  focusRing;

/** What the form is for: a new ballot, or this one. */
type FormState = { mode: 'new' } | { mode: 'edit'; id: string };

/** What the party modal is for: a new party, or this one. */
type PartyFormState = { mode: 'new' } | { mode: 'edit'; id: string };

/** What the candidate modal is for: a new candidate in this ballot, or this candidate. */
type CandidateFormState = { mode: 'new'; ballot: string } | { mode: 'edit'; id: string };

/** A glass button on the header band (the second action). */
const GLASS =
  'ui-control lift-sm inline-flex h-12 items-center justify-center gap-2 rounded-full border border-glass-line bg-glass pr-5.5 pl-4 text-md font-bold text-surface hover:bg-glass-line max-md:w-full ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-surface';

/** The ballot being dragged by its grip, and the one under the pointer. */
type Drag = { id: string; over: string };

/** The candidate being dragged by its grip, the ballot it is in, and the one under the pointer. */
type CandidateDrag = { ballot: string; id: string; over: string };

/** The id of the candidate row under a point of the screen, inside this ballot, or null. */
function candidateAt(x: number, y: number, ballot: string): string | null {
  const row = document.elementFromPoint(x, y)?.closest('[data-candidate-id]');

  return row?.getAttribute('data-candidate-ballot') === ballot
    ? row.getAttribute('data-candidate-id')
    : null;
}

/** The id of the ballot card under a point of the screen, or null. */
function ballotAt(x: number, y: number): string | null {
  const card = document.elementFromPoint(x, y)?.closest('[data-ballot-id]');

  return card?.getAttribute('data-ballot-id') ?? null;
}

/**
 * Screen A06: the positions (ballots) of one election. A compact header band with the status, the
 * title, the chips and the main action, then one white zone holding a card per ballot and a dashed
 * creation tile; the rail beside it from 1600 px and under it below. A draft can be changed
 * (add, edit, delete, reorder); any other election is shown as it is.
 *
 * Reordering is optimistic: the list changes at once and one PUT saves the whole order. Saves
 * never overlap (a change made while one is in flight is sent when it ends), and a failure brings
 * back the last order the server confirmed, with a notice.
 */
export function BallotsPage({
  election,
  initial,
  initialParties,
}: {
  election: Election;
  initial: Ballot[];
  initialParties: Party[];
}) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const [, startTransition] = useTransition();
  const editable = election.status === 'draft';

  const [ballots, setBallots] = useState<Ballot[]>(initial);
  const [form, setForm] = useState<FormState | null>(null);
  const [deleting, setDeleting] = useState<Ballot | null>(null);
  const [parties, setParties] = useState<Party[]>(initialParties);
  const [partyForm, setPartyForm] = useState<PartyFormState | null>(null);
  const [deletingParty, setDeletingParty] = useState<Party | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [announce, setAnnounce] = useState('');
  const [drag, setDrag] = useState<Drag | null>(null);
  const [candidateForm, setCandidateForm] = useState<CandidateFormState | null>(null);
  const [deletingCandidate, setDeletingCandidate] = useState<Candidate | null>(null);
  const [candidateDrag, setCandidateDrag] = useState<CandidateDrag | null>(null);

  // What the server last confirmed, and the save in flight (kept out of the render).
  const confirmed = useRef<Ballot[]>(initial);
  const save = useRef<{ running: boolean; next: Ballot[] | null }>({ running: false, next: null });
  // After a move by button, the focus follows the ballot to its new place.
  const refocus = useRef<{ id: string; step: -1 | 1 } | null>(null);
  // The saves of the candidates of each ballot, one at a time per ballot, like the ballots'.
  const candidateSaves = useRef(new Map<string, { running: boolean; next: Candidate[] | null }>());
  // After a move by button, the focus follows the candidate; after a delete it goes to the add button.
  const refocusCandidate = useRef<{ id: string; step: -1 | 1 } | null>(null);
  const refocusAdd = useRef<string | null>(null);

  useEffect(() => {
    const target = refocus.current;

    if (!target) return;

    refocus.current = null;

    const place = ballots.findIndex((ballot) => ballot.id === target.id) + 1;

    if (place === 0) return;

    const wanted = document.querySelector<HTMLButtonElement>(
      `[data-testid="ballot-${target.step === 1 ? 'down' : 'up'}-${place}"]`,
    );
    const other = document.querySelector<HTMLButtonElement>(
      `[data-testid="ballot-${target.step === 1 ? 'up' : 'down'}-${place}"]`,
    );

    // At the end of the list the button that was pressed is disabled: the other one is next.
    (wanted && !wanted.disabled ? wanted : other)?.focus();
  }, [ballots]);

  useEffect(() => {
    const target = refocusCandidate.current;

    if (target) {
      refocusCandidate.current = null;

      for (const [b, ballot] of ballots.entries()) {
        const place = ballot.candidates.findIndex((candidate) => candidate.id === target.id) + 1;

        if (place === 0) continue;

        const wanted = document.querySelector<HTMLButtonElement>(
          `[data-testid="candidate-${target.step === 1 ? 'down' : 'up'}-${b + 1}-${place}"]`,
        );
        const other = document.querySelector<HTMLButtonElement>(
          `[data-testid="candidate-${target.step === 1 ? 'up' : 'down'}-${b + 1}-${place}"]`,
        );

        (wanted && !wanted.disabled ? wanted : other)?.focus();
      }
    }

    const add = refocusAdd.current;

    if (add) {
      refocusAdd.current = null;
      document
        .querySelector<HTMLElement>(`[data-ballot-id="${add}"] [data-candidate-add]`)
        ?.focus();
    }
  }, [ballots]);

  // After a delete, the row the focus was on is gone: it goes to the button that adds a party.
  const refocusParties = useRef(false);

  useEffect(() => {
    if (!refocusParties.current) return;

    refocusParties.current = false;
    document.querySelector<HTMLElement>('[data-testid="party-new"]')?.focus();
  }, [parties]);

  /** The same change on what is shown and on what the server knows (create, edit, delete). */
  function apply(change: (list: Ballot[]) => Ballot[]) {
    confirmed.current = change(confirmed.current);
    setBallots((list) => change(list));
  }

  /**
   * The server's own candidates, read again after a save: they replace the ones shown, while the
   * ballots keep their order. Skipped while a save of the order is on its way (it would bring
   * back the old order for a moment).
   */
  function refreshCandidates() {
    void Promise.resolve(fetchBallots(election.id)).then(
      (fresh) => {
        const busy =
          save.current.running ||
          Array.from(candidateSaves.current.values()).some((slot) => slot.running);

        if (!Array.isArray(fresh) || busy) return;

        confirmed.current = mergeCandidates(confirmed.current, fresh);
        setBallots((list) => mergeCandidates(list, fresh));
      },
      () => undefined,
    );
  }

  /** A locked election (or one that changed elsewhere): the notice, the real list and status. */
  async function locked() {
    setForm(null);
    setDeleting(null);
    setCandidateForm(null);
    setDeletingCandidate(null);
    setPartyForm(null);
    setDeletingParty(null);
    setProblem(t('ballots.notEditable'));

    try {
      const fresh = await fetchBallots(election.id);

      confirmed.current = fresh;
      setBallots(fresh);
    } catch {
      // The notice stands; the list is read again with the page below.
    }

    startTransition(() => router.refresh());
  }

  /** The server's list is not the one that was sent (422 `set_mismatch`): read it again and show it. */
  async function resyncBallots() {
    try {
      const fresh = await fetchBallots(election.id);

      confirmed.current = fresh;
      setBallots(fresh);
      setProblem(t('ballots.listChanged'));
    } catch {
      setBallots(confirmed.current);
      setProblem(t('ballots.reorderFailed'));
    }
  }

  /** The same for the candidates of one ballot: only that ballot's candidates are read again. */
  async function resyncCandidates(ballotId: string) {
    try {
      const fresh = await fetchBallots(election.id);
      const server = fresh.find((ballot) => ballot.id === ballotId);

      if (server) {
        confirmed.current = withCandidates(confirmed.current, ballotId, server.candidates);
        setBallots((list) => withCandidates(list, ballotId, server.candidates));
      }

      setProblem(t('candidates.listChanged'));
    } catch {
      const back = confirmed.current.find((ballot) => ballot.id === ballotId)?.candidates ?? [];

      setBallots((list) => withCandidates(list, ballotId, back));
      setProblem(t('candidates.reorderFailed'));
    }
  }

  async function run(first: Ballot[]) {
    save.current.running = true;

    let target: Ballot[] | null = first;

    while (target) {
      try {
        const saved = await reorderBallots(election.id, orderOf(target));

        // Only the order and the positions are taken from the answer: a ballot or a candidate
        // added or edited meanwhile is kept.
        confirmed.current = mergeOrder(confirmed.current, saved);

        // Nothing newer waits: show the positions the server set.
        if (!save.current.next) setBallots((list) => mergeOrder(list, saved));
      } catch (caught) {
        save.current = { running: false, next: null };
        setBallots(confirmed.current);

        const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

        if (failure.code === 'election_not_editable') void locked();
        else if (failure.status === 422 && failure.fields.ballots?.includes('set_mismatch')) {
          void resyncBallots();
        } else setProblem(`${t('ballots.reorderFailed')} ${errorText(failure, tIfAny)}`);

        return;
      }

      target = save.current.next;
      save.current.next = null;
    }

    save.current.running = false;
  }

  /** Puts the list in this order on the screen at once and saves it. */
  function commit(next: Ballot[], moved: Ballot) {
    if (sameOrder(next, ballots)) return;

    setProblem(null);
    setBallots(next);
    setAnnounce(
      t('ballots.card.moved', {
        title: moved.title,
        position: next.findIndex((ballot) => ballot.id === moved.id) + 1,
        total: next.length,
      }),
    );

    if (save.current.running) save.current.next = next;
    else void run(next);
  }

  function move(ballot: Ballot, step: -1 | 1) {
    refocus.current = { id: ballot.id, step };
    commit(moveBy(ballots, ballot.id, step), ballot);
  }

  function gripDown(event: ReactPointerEvent<HTMLElement>, ballot: Ballot) {
    // The main button of a mouse, or any touch or pen.
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    setDrag({ id: ballot.id, over: ballot.id });
  }

  function gripMove(event: ReactPointerEvent<HTMLElement>) {
    if (!drag) return;

    const over = ballotAt(event.clientX, event.clientY) ?? drag.over;

    if (over !== drag.over) setDrag({ ...drag, over });
  }

  function gripUp(event: ReactPointerEvent<HTMLElement>, cancelled: boolean) {
    if (!drag) return;

    const onto = cancelled ? drag.id : (ballotAt(event.clientX, event.clientY) ?? drag.over);
    const moved = ballots.find((ballot) => ballot.id === drag.id);

    setDrag(null);

    if (moved && onto !== drag.id) commit(dropOn(ballots, drag.id, onto), moved);
  }

  /** Puts this ballot's candidates in this order on the screen at once and saves it. */
  function commitCandidates(ballot: Ballot, next: Candidate[], moved: Candidate) {
    if (next.every((candidate, index) => candidate.id === ballot.candidates[index]?.id)) return;

    const name = `${moved.first_name} ${moved.last_name}`.trim();

    setProblem(null);
    setBallots((list) => withCandidates(list, ballot.id, next));
    setAnnounce(
      t('candidates.row.moved', {
        name,
        position: next.findIndex((candidate) => candidate.id === moved.id) + 1,
        total: next.length,
      }),
    );

    const slots = candidateSaves.current;
    const slot = slots.get(ballot.id) ?? { running: false, next: null };

    slots.set(ballot.id, slot);

    if (slot.running) slot.next = next;
    else void runCandidates(ballot.id, next);
  }

  async function runCandidates(ballotId: string, first: Candidate[]) {
    const slot = candidateSaves.current.get(ballotId)!;

    slot.running = true;

    let target: Candidate[] | null = first;

    while (target) {
      try {
        const saved = await reorderCandidates(
          ballotId,
          target.map((candidate) => candidate.id),
        );

        // Only the order and the positions are taken from the answer (see run).
        confirmed.current = withCandidateOrder(confirmed.current, ballotId, saved);

        // Nothing newer waits: show the positions the server set.
        if (!slot.next) setBallots((list) => withCandidateOrder(list, ballotId, saved));
      } catch (caught) {
        slot.running = false;
        slot.next = null;

        const back = confirmed.current.find((ballot) => ballot.id === ballotId)?.candidates ?? [];

        setBallots((list) => withCandidates(list, ballotId, back));

        const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

        if (failure.code === 'election_not_editable') void locked();
        else if (failure.status === 422 && failure.fields.candidates?.includes('set_mismatch')) {
          void resyncCandidates(ballotId);
        } else setProblem(`${t('candidates.reorderFailed')} ${errorText(failure, tIfAny)}`);

        return;
      }

      target = slot.next;
      slot.next = null;
    }

    slot.running = false;
  }

  function moveCandidate(ballot: Ballot, candidate: Candidate, step: -1 | 1) {
    refocusCandidate.current = { id: candidate.id, step };
    commitCandidates(ballot, moveBy(ballot.candidates, candidate.id, step), candidate);
  }

  function candidateGripDown(
    event: ReactPointerEvent<HTMLElement>,
    ballot: Ballot,
    candidate: Candidate,
  ) {
    if (event.pointerType === 'mouse' && event.button !== 0) return;

    event.preventDefault();
    event.currentTarget.setPointerCapture(event.pointerId);
    setCandidateDrag({ ballot: ballot.id, id: candidate.id, over: candidate.id });
  }

  function candidateGripMove(event: ReactPointerEvent<HTMLElement>) {
    if (!candidateDrag) return;

    const over =
      candidateAt(event.clientX, event.clientY, candidateDrag.ballot) ?? candidateDrag.over;

    if (over !== candidateDrag.over) setCandidateDrag({ ...candidateDrag, over });
  }

  function candidateGripUp(event: ReactPointerEvent<HTMLElement>, cancelled: boolean) {
    if (!candidateDrag) return;

    const onto = cancelled
      ? candidateDrag.id
      : (candidateAt(event.clientX, event.clientY, candidateDrag.ballot) ?? candidateDrag.over);
    const ballot = ballots.find((item) => item.id === candidateDrag.ballot);
    const moved = ballot?.candidates.find((item) => item.id === candidateDrag.id);

    setCandidateDrag(null);

    if (ballot && moved && onto !== candidateDrag.id) {
      commitCandidates(ballot, dropOn(ballot.candidates, candidateDrag.id, onto), moved);
    }
  }

  const editing = form?.mode === 'edit' ? (ballots.find((b) => b.id === form.id) ?? null) : null;
  const showForm = editable && form !== null && (form.mode === 'new' || editing !== null);
  const count = countText(ballots.length, locale, t);
  const editingCandidate =
    candidateForm?.mode === 'edit'
      ? (ballots.flatMap((item) => item.candidates).find((c) => c.id === candidateForm.id) ?? null)
      : null;
  const showCandidateForm =
    editable &&
    candidateForm !== null &&
    (candidateForm.mode === 'new'
      ? ballots.some((b) => b.id === candidateForm.ballot)
      : editingCandidate !== null);
  // The party counts are the real ones: counted on the candidates the page holds.
  const counts = partyCounts(ballots);
  const shownParties = parties.map((party) => ({
    ...party,
    candidates_count: counts.get(party.id) ?? 0,
  }));
  const candidateActions: CandidateActions = {
    drag: null,
    onAdd: (ballot) => setCandidateForm({ mode: 'new', ballot: ballot.id }),
    onMove: moveCandidate,
    onEdit: (candidate) => setCandidateForm({ mode: 'edit', id: candidate.id }),
    onDelete: setDeletingCandidate,
    onGripDown: candidateGripDown,
    onGripMove: candidateGripMove,
    onGripUp: candidateGripUp,
  };
  const editingParty =
    partyForm?.mode === 'edit' ? (parties.find((p) => p.id === partyForm.id) ?? null) : null;
  const showPartyForm =
    editable && partyForm !== null && (partyForm.mode === 'new' || editingParty !== null);

  return (
    <div data-testid="ballots-page" className="flex flex-col gap-4.5">
      <Link
        href={`/admin/elections/${election.id}`}
        data-testid="ballots-back"
        className={cx(
          'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink',
          focusRing,
        )}
      >
        <Icon name="left" size={18} />
        {t('ballots.back')}
      </Link>

      <section
        data-testid="ballots-hero"
        data-status={election.status}
        aria-labelledby="ballots-title"
        className="election-hero cover-sweep relative flex min-h-32 flex-wrap items-end gap-x-6 gap-y-4 rounded-xl px-4 py-5 text-surface shadow-2 md:px-7.5 [@media(max-height:820px)]:min-h-24 [@media(max-height:820px)]:py-3.5"
      >
        <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
          <Icon name={COVER_ICONS[election.status]} size={210} />
        </span>
        <div className="relative z-10 min-w-0 flex-[1_1_22rem]">
          <span
            data-testid="ballots-badge"
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
                ? t('ballots.badgeDraft')
                : t(`elections.status.${election.status}`)}
            </span>
          </span>
          <h2
            id="ballots-title"
            data-testid="ballots-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('ballots.title')}
          </h2>
          <p
            data-testid="ballots-election"
            className="line-clamp-2 text-md break-words text-surface"
          >
            {election.title}
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span data-testid="ballots-count" className={CHIP}>
              <Icon name="flag" size={18} />
              {count}
            </span>
            <span data-testid="candidates-count" className={CHIP}>
              <Icon name="people" size={18} />
              {candidateCountText(candidateTotal(ballots), locale, t)}
            </span>
            <span data-testid="parties-count" className={CHIP}>
              <Icon name="party" size={18} />
              {partiesText(parties.length, locale, t)}
            </span>
            <span data-testid="ballots-order" className={CHIP}>
              <Icon name="shuffle" size={18} />
              {t(`elections.form.order.${election.candidate_order}`)}
            </span>
          </div>
        </div>

        {editable ? (
          <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
            <button
              type="button"
              data-testid="ballots-add"
              onClick={() => setForm({ mode: 'new' })}
              className={ADD}
            >
              <Icon name="plus" size={20} />
              {t('ballots.add')}
            </button>
            <button
              type="button"
              data-testid="parties-add"
              onClick={() => setPartyForm({ mode: 'new' })}
              className={GLASS}
            >
              <Icon name="party" size={20} />
              {t('parties.add')}
            </button>
          </div>
        ) : null}
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="ballots-notice">
          {problem}
        </Notice>
      ) : null}

      {editable ? null : (
        <Notice tone="info" data-testid="ballots-locked">
          {t('ballots.locked')}
        </Notice>
      )}

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <section
          data-testid="ballots-zone"
          aria-label={t('ballots.zone.label')}
          className="flex min-w-0 flex-1 flex-col gap-4 rounded-2xl border border-line bg-surface p-2.5 shadow-1 md:p-3 2xl:p-5"
        >
          <div className="flex flex-col gap-0.5 px-1 md:flex-row md:items-center md:gap-3">
            <h2 className="font-display text-xl font-extrabold text-ink">
              {t('ballots.zone.title')}
            </h2>
            {editable && ballots.length > 1 ? (
              <p className="text-base text-ink-soft">{t('ballots.zone.hint')}</p>
            ) : null}
          </div>

          {showForm ? (
            <BallotForm
              key={form.mode === 'edit' ? form.id : 'new'}
              election={election.id}
              ballot={editing}
              onSaved={(saved) => {
                apply((list) =>
                  form.mode === 'edit'
                    ? list.map((ballot) => (ballot.id === saved.id ? saved : ballot))
                    : [...list, saved],
                );
                setProblem(null);
                setForm(null);
              }}
              onCancel={() => setForm(null)}
              onLocked={() => void locked()}
            />
          ) : null}

          <RevealList
            aria-label={t('ballots.zone.title')}
            data-testid="ballots-grid"
            className="grid grid-cols-[repeat(auto-fill,minmax(min(16.875rem,100%),1fr))] gap-4.5 2xl:grid-cols-[repeat(auto-fill,minmax(min(22.5rem,100%),1fr))]"
          >
            {ballots.map((ballot, index) => (
              <BallotCard
                key={ballot.id}
                ballot={ballot}
                n={index + 1}
                total={ballots.length}
                editable={editable}
                parties={parties}
                candidates={{
                  ...candidateActions,
                  drag:
                    candidateDrag?.ballot === ballot.id
                      ? { id: candidateDrag.id, over: candidateDrag.over }
                      : null,
                }}
                dragging={drag?.id === ballot.id}
                over={drag !== null && drag.id !== ballot.id && drag.over === ballot.id}
                onMove={move}
                onEdit={(chosen) => setForm({ mode: 'edit', id: chosen.id })}
                onDelete={setDeleting}
                onGripDown={gripDown}
                onGripMove={gripMove}
                onGripUp={gripUp}
              />
            ))}
            {editable ? (
              <li className="flex min-w-0">
                <button
                  type="button"
                  data-testid="ballot-new-tile"
                  onClick={() => setForm({ mode: 'new' })}
                  className={cx(
                    'lift flex min-h-44 w-full flex-col items-center justify-center gap-2.5 rounded-lg border-2 border-dashed border-primary-line bg-surface-alt p-4 text-center text-status-scheduled hover:bg-primary-soft',
                    focusRing,
                  )}
                >
                  <span
                    aria-hidden="true"
                    className="bob flex size-16 items-center justify-center rounded-full bg-primary text-surface shadow-button"
                  >
                    <Icon name="plus" size={30} />
                  </span>
                  <span className="font-display text-lg font-extrabold">
                    {t('ballots.newTile.title')}
                  </span>
                  <small className="max-w-60 text-base font-normal text-ink-soft">
                    {t('ballots.newTile.hint')}
                  </small>
                </button>
              </li>
            ) : null}
          </RevealList>
        </section>

        <BallotsRail
          parties={shownParties}
          checks={checksOf(ballots)}
          editable={editable}
          onAddCandidate={(ballot) => setCandidateForm({ mode: 'new', ballot: ballot.id })}
          onNewParty={() => setPartyForm({ mode: 'new' })}
          onEditParty={(chosen) => setPartyForm({ mode: 'edit', id: chosen.id })}
          onDeleteParty={setDeletingParty}
        />
      </div>

      <p role="status" aria-live="polite" className="sr-only">
        {announce}
      </p>

      {showPartyForm ? (
        <PartyModal
          key={partyForm.mode === 'edit' ? partyForm.id : 'new'}
          election={election.id}
          party={editingParty}
          onSaved={(saved) => {
            setParties((list) =>
              list.some((party) => party.id === saved.id)
                ? list.map((party) => (party.id === saved.id ? saved : party))
                : [...list, saved],
            );
            // The API's order (by name) is the one shown: read it again, in place.
            void fetchParties(election.id).then(setParties, () => undefined);
          }}
          onLocked={() => void locked()}
          onClose={() => setPartyForm(null)}
        />
      ) : null}

      {deletingParty ? (
        <DeletePartyDialog
          party={deletingParty}
          onLocked={() => void locked()}
          onCancel={() => setDeletingParty(null)}
          onDeleted={() => {
            const gone = deletingParty.id;

            refocusParties.current = true;
            setParties((list) => list.filter((party) => party.id !== gone));
            // Its candidates stay and become independent.
            apply((list) => detachParty(list, gone));
            refreshCandidates();
            setDeletingParty(null);
          }}
        />
      ) : null}

      {showCandidateForm ? (
        <CandidateModal
          key={candidateForm.mode === 'edit' ? candidateForm.id : 'new'}
          ballots={ballots}
          parties={parties}
          candidate={editingCandidate}
          ballot={candidateForm.mode === 'new' ? candidateForm.ballot : ''}
          onSaved={(saved) => {
            apply((list) => placeCandidate(list, saved));
            setProblem(null);
            refreshCandidates();
          }}
          onLocked={() => void locked()}
          onClose={() => setCandidateForm(null)}
        />
      ) : null}

      {deletingCandidate ? (
        <DeleteCandidateDialog
          candidate={deletingCandidate}
          onLocked={() => void locked()}
          onCancel={() => setDeletingCandidate(null)}
          onDeleted={() => {
            const gone = deletingCandidate;

            refocusAdd.current = gone.ballot;
            apply((list) => removeCandidate(list, gone.id));
            setDeletingCandidate(null);
            refreshCandidates();
          }}
        />
      ) : null}

      {deleting ? (
        <DeleteBallotDialog
          ballot={deleting}
          onCancel={() => setDeleting(null)}
          onLocked={() => void locked()}
          onDeleted={() => {
            const gone = deleting.id;

            apply((list) =>
              list
                .filter((ballot) => ballot.id !== gone)
                .map((ballot, index) => ({ ...ballot, position: index + 1 })),
            );
            setDeleting(null);
          }}
        />
      ) : null}
    </div>
  );
}
