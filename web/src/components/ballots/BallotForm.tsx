'use client';

import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { Panel } from '@/components/elections/Panel';
import { Button, Input, Notice, Textarea } from '@/components/ui';
import type { Ballot } from '@/lib/api/ballots';
import { createBallot, updateBallot } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import {
  apiFieldErrors,
  bodyOf,
  DESCRIPTION_MAX,
  EMPTY_DRAFT,
  SEATS_MAX,
  SEATS_MIN,
  TITLE_MAX,
  validateBallot,
  type BallotDraft,
  type BallotFieldErrors,
} from './ballotForm';

function draftOf(ballot: Ballot | null): BallotDraft {
  if (!ballot) return EMPTY_DRAFT;

  return {
    title: ballot.title,
    description: ballot.description ?? '',
    seats: String(ballot.seats),
    allowBlank: ballot.allow_blank,
  };
}

/**
 * The fields and buttons of the ballot form. The title is checked before the call; the API's own
 * answers (a field that fails, a full election, a locked one) come back beside the field or in
 * one notice, and the form stays open. A locked election is the page's to deal with.
 */
function Fields({
  election,
  ballot,
  onSaved,
  onCancel,
  onLocked,
  inDialog,
}: {
  election: string;
  ballot: Ballot | null;
  onSaved: (ballot: Ballot) => void;
  onCancel: () => void;
  onLocked: () => void;
  inDialog: boolean;
}) {
  const { t, tIfAny } = useI18n();
  const [draft, setDraft] = useState<BallotDraft>(() => draftOf(ballot));
  const [errors, setErrors] = useState<BallotFieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const first = useRef<HTMLInputElement>(null);

  // On a desktop the panel opens at the top of the zone: the title field takes the focus (a
  // dialog does it by itself).
  useEffect(() => {
    if (inDialog) return;

    first.current?.focus({ preventScroll: true });
    first.current?.scrollIntoView?.({ block: 'nearest' });
  }, [inDialog]);

  function text(field: keyof BallotFieldErrors): string | undefined {
    const code = errors[field];

    return code ? fieldText(`ballot.${field}`, code, tIfAny) : undefined;
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    const found = validateBallot(draft);

    setProblem(null);
    setErrors(found);

    if (Object.keys(found).length > 0) {
      if (found.title) first.current?.focus();

      return;
    }

    setBusy(true);

    try {
      const saved = ballot
        ? await updateBallot(ballot.id, bodyOf(draft))
        : await createBallot(election, bodyOf(draft));

      onSaved(saved);
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setBusy(false);

      if (failure.code === 'election_not_editable') {
        onLocked();

        return;
      }

      const own = apiFieldErrors(failure.fields);

      if (failure.status === 422 && Object.keys(own).length > 0) {
        setErrors(own);

        return;
      }

      setProblem(errorText(failure, tIfAny));
    }
  }

  return (
    <form
      noValidate
      onSubmit={submit}
      aria-label={t(ballot ? 'ballots.form.titleEdit' : 'ballots.form.titleNew')}
      className="flex flex-col gap-4"
    >
      <Input
        ref={first}
        label={t('ballots.form.title')}
        value={draft.title}
        onChange={(event) => setDraft({ ...draft, title: event.target.value })}
        maxLength={TITLE_MAX + 50}
        autoComplete="off"
        required
        error={text('title')}
        errorTestId="ballot-form-error-title"
        data-testid="ballot-form-title"
      />
      <Textarea
        label={t('ballots.form.description')}
        value={draft.description}
        onChange={(event) => setDraft({ ...draft, description: event.target.value })}
        maxLength={DESCRIPTION_MAX + 100}
        rows={3}
        error={text('description')}
        errorTestId="ballot-form-error-description"
        data-testid="ballot-form-description"
      />
      <div className="flex flex-wrap items-start gap-x-6 gap-y-3">
        <div className="w-full max-w-48 min-w-36">
          <Input
            type="number"
            inputMode="numeric"
            min={SEATS_MIN}
            max={SEATS_MAX}
            label={t('ballots.form.seats')}
            help={t('ballots.form.seatsHelp')}
            value={draft.seats}
            onChange={(event) => setDraft({ ...draft, seats: event.target.value })}
            error={text('seats')}
            errorTestId="ballot-form-error-seats"
            data-testid="ballot-form-seats"
          />
        </div>
        <label className="flex min-h-11 cursor-pointer items-center gap-3 self-center text-md font-medium text-ink">
          <input
            type="checkbox"
            checked={draft.allowBlank}
            onChange={(event) => setDraft({ ...draft, allowBlank: event.target.checked })}
            data-testid="ballot-form-blank"
            className={`size-5 shrink-0 cursor-pointer accent-primary ${focusRing}`}
          />
          {t('ballots.form.blank')}
        </label>
      </div>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="ballot-form-alert">
          {t('ballots.form.error')} {problem}
        </Notice>
      ) : null}

      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <Button
          variant="secondary"
          disabled={busy}
          onClick={onCancel}
          data-testid="ballot-form-cancel"
        >
          {t('ballots.form.cancel')}
        </Button>
        <Button type="submit" loading={busy} data-testid="ballot-form-save">
          <span className="inline-flex items-center gap-2">
            <Icon name="check" size={18} />
            {t(ballot ? 'ballots.form.save' : 'ballots.form.add')}
          </span>
        </Button>
      </div>
    </form>
  );
}

const PHONE = '(max-width: 767px)';

/** True under 768 px, where the form is a dialog (a phone) instead of a panel in the zone. */
function usePhone(): boolean {
  // Read at once: the form only exists after a tap, long after the page was hydrated.
  const [phone, setPhone] = useState(
    () => typeof window.matchMedia === 'function' && window.matchMedia(PHONE).matches,
  );

  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return;

    const query = window.matchMedia(PHONE);

    function sync() {
      setPhone(query.matches);
    }

    sync();
    query.addEventListener('change', sync);

    return () => query.removeEventListener('change', sync);
  }, []);

  return phone;
}

/** The native modal dialog of a phone: Escape and the backdrop cancel, the focus returns on close. */
function FormDialog({
  title,
  onCancel,
  children,
}: {
  title: string;
  onCancel: () => void;
  children: ReactNode;
}) {
  const dialog = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const element = dialog.current;

    if (element && !element.open) element.showModal();

    return () => {
      if (element?.open) element.close();
    };
  }, []);

  function dismiss() {
    dialog.current?.close();
    onCancel();
  }

  return (
    <dialog
      ref={dialog}
      data-testid="ballot-form"
      aria-label={title}
      onCancel={(event) => {
        event.preventDefault();
        dismiss();
      }}
      onClick={(event) => {
        if (event.target === dialog.current) dismiss();
      }}
      className="m-auto max-h-[92vh] w-[min(94vw,32rem)] overflow-y-auto rounded-lg border border-line bg-surface p-0 text-ink shadow-3 backdrop:bg-deep/60"
    >
      <div className="flex flex-col gap-4 p-4">
        <h2 className="font-display text-xl font-extrabold text-ink">{title}</h2>
        {children}
      </div>
    </dialog>
  );
}

/**
 * The form to add a ballot (`ballot` null) or change one: a dialog on a phone, a panel at the top
 * of the zone on a desktop. `onSaved` receives the ballot as the API gives it back.
 */
export function BallotForm({
  election,
  ballot,
  onSaved,
  onCancel,
  onLocked,
}: {
  election: string;
  ballot: Ballot | null;
  onSaved: (ballot: Ballot) => void;
  onCancel: () => void;
  onLocked: () => void;
}) {
  const { t } = useI18n();
  const phone = usePhone();
  const title = t(ballot ? 'ballots.form.titleEdit' : 'ballots.form.titleNew');
  const fields = (
    <Fields
      key={phone ? 'dialog' : 'panel'}
      election={election}
      ballot={ballot}
      onSaved={onSaved}
      onCancel={onCancel}
      onLocked={onLocked}
      inDialog={phone}
    />
  );

  if (phone) {
    return (
      <FormDialog title={title} onCancel={onCancel}>
        {fields}
      </FormDialog>
    );
  }

  return (
    <Panel
      tone="info"
      icon={ballot ? 'edit' : 'plus'}
      title={title}
      id="ballot-form-heading"
      testId="ballot-form"
      plain
    >
      {fields}
    </Panel>
  );
}
