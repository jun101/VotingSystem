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
import { fetchBallots, reorderBallots } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { BallotCard } from './BallotCard';
import { BallotForm } from './BallotForm';
import { BallotsRail } from './BallotsRail';
import { DeleteBallotDialog } from './DeleteBallotDialog';
import { dropOn, moveBy, orderOf, sameOrder } from './ballotMove';
import { countText } from './ballotText';

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

/** The ballot being dragged by its grip, and the one under the pointer. */
type Drag = { id: string; over: string };

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
export function BallotsPage({ election, initial }: { election: Election; initial: Ballot[] }) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const [, startTransition] = useTransition();
  const editable = election.status === 'draft';

  const [ballots, setBallots] = useState<Ballot[]>(initial);
  const [form, setForm] = useState<FormState | null>(null);
  const [deleting, setDeleting] = useState<Ballot | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [announce, setAnnounce] = useState('');
  const [drag, setDrag] = useState<Drag | null>(null);

  // What the server last confirmed, and the save in flight (kept out of the render).
  const confirmed = useRef<Ballot[]>(initial);
  const save = useRef<{ running: boolean; next: Ballot[] | null }>({ running: false, next: null });
  // After a move by button, the focus follows the ballot to its new place.
  const refocus = useRef<{ id: string; step: -1 | 1 } | null>(null);

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

  /** The same change on what is shown and on what the server knows (create, edit, delete). */
  function apply(change: (list: Ballot[]) => Ballot[]) {
    confirmed.current = change(confirmed.current);
    setBallots((list) => change(list));
  }

  /** A locked election (or one that changed elsewhere): the notice, the real list and status. */
  async function locked() {
    setForm(null);
    setDeleting(null);
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

  async function run(first: Ballot[]) {
    save.current.running = true;

    let target: Ballot[] | null = first;

    while (target) {
      try {
        const saved = await reorderBallots(election.id, orderOf(target));

        confirmed.current = saved;

        // Nothing newer waits: show what the server answered (the positions it set).
        if (!save.current.next) setBallots(saved);
      } catch (caught) {
        save.current = { running: false, next: null };
        setBallots(confirmed.current);

        const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

        if (failure.code === 'election_not_editable') void locked();
        else setProblem(`${t('ballots.reorderFailed')} ${errorText(failure, tIfAny)}`);

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

  const editing = form?.mode === 'edit' ? (ballots.find((b) => b.id === form.id) ?? null) : null;
  const showForm = editable && form !== null && (form.mode === 'new' || editing !== null);
  const count = countText(ballots.length, locale, t);

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

        <BallotsRail />
      </div>

      <p role="status" aria-live="polite" className="sr-only">
        {announce}
      </p>

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
