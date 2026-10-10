'use client';

import { useRef, useState, type FormEvent } from 'react';
import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { createParty, updateParty } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import {
  ACRONYM_MAX,
  apiFieldErrors,
  bodyOf,
  colourValue,
  draftOf,
  EMPTY_DRAFT,
  NAME_MAX,
  PARTY_COLOURS,
  validateParty,
  type PartyDraft,
  type PartyFieldErrors,
} from './partyForm';

/**
 * The party modal: a new party (`party` null) or the values of one. The name is checked before
 * the call; the API's own answers (a name already used, a full election, a locked one) come back
 * beside the field or in one notice, and the modal stays open. "Save and add another" (creation
 * only) keeps it open with the form cleared and the focus on the name. `onSaved` receives the
 * party as the API gives it back, for the page to show in place.
 */
export function PartyModal({
  election,
  party,
  onSaved,
  onClose,
}: {
  election: string;
  party: Party | null;
  onSaved: (party: Party) => void;
  onClose: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [draft, setDraft] = useState<PartyDraft>(() => (party ? draftOf(party) : EMPTY_DRAFT));
  const [errors, setErrors] = useState<PartyFieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [added, setAdded] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const name = useRef<HTMLInputElement>(null);

  function text(field: keyof PartyFieldErrors): string | undefined {
    const code = errors[field];

    return code ? fieldText(`party.${field}`, code, tIfAny) : undefined;
  }

  async function submit(another: boolean) {
    const found = validateParty(draft);

    setProblem(null);
    setAdded(null);
    setErrors(found);

    if (Object.keys(found).length > 0) {
      if (found.name || found.acronym) name.current?.focus();

      return;
    }

    setBusy(true);

    try {
      const saved = party
        ? await updateParty(party.id, bodyOf(draft))
        : await createParty(election, bodyOf(draft));

      onSaved(saved);

      if (!another || party) {
        onClose();

        return;
      }

      setDraft(EMPTY_DRAFT);
      setAdded(saved.name);
      setBusy(false);
      // The field is enabled again when the page has drawn; the focus goes there at once.
      name.current?.focus();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setBusy(false);

      const own = apiFieldErrors(failure.fields);

      if (failure.status === 422 && Object.keys(own).length > 0) {
        setErrors(own);

        if (own.name) name.current?.focus();

        return;
      }

      setProblem(errorText(failure, tIfAny));
    }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    void submit(false);
  }

  return (
    <Modal
      title={t(party ? 'parties.form.titleEdit' : 'parties.form.titleNew')}
      icon="party"
      closeLabel={t('parties.form.close')}
      onClose={onClose}
      busy={busy}
      testId="party-modal"
      closeTestId="party-modal-close"
    >
      <form
        noValidate
        onSubmit={onSubmit}
        aria-label={t(party ? 'parties.form.titleEdit' : 'parties.form.titleNew')}
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <div className="grid grid-cols-[minmax(0,1fr)_minmax(5.5rem,7.5rem)] items-start gap-3">
          <Input
            ref={name}
            label={t('parties.form.name')}
            value={draft.name}
            onChange={(event) => setDraft({ ...draft, name: event.target.value })}
            maxLength={NAME_MAX + 20}
            autoComplete="off"
            required
            error={text('name')}
            errorTestId="party-form-error-name"
            data-testid="party-form-name"
            data-autofocus=""
          />
          <Input
            label={t('parties.form.acronym')}
            value={draft.acronym}
            onChange={(event) => setDraft({ ...draft, acronym: event.target.value })}
            maxLength={ACRONYM_MAX + 10}
            autoComplete="off"
            error={text('acronym')}
            errorTestId="party-form-error-acronym"
            data-testid="party-form-acronym"
          />
        </div>

        <fieldset className="flex min-w-0 flex-col gap-2.5">
          <legend className="mb-2.5 text-xs font-bold tracking-wider text-ink-soft uppercase">
            {t('parties.form.colour')}
          </legend>
          <div className="flex flex-wrap gap-3">
            {PARTY_COLOURS.map((digits) => (
              <span key={digits} className="relative inline-flex size-9">
                <input
                  type="radio"
                  name="party-colour"
                  value={digits}
                  checked={draft.colour === digits}
                  onChange={() => setDraft({ ...draft, colour: digits })}
                  aria-label={t(`parties.colours.${digits}`)}
                  data-testid={`party-form-colour-${digits}`}
                  style={{ backgroundColor: colourValue(digits) }}
                  className={cx(
                    'peer size-9 cursor-pointer appearance-none rounded-full shadow-1 transition-shadow',
                    'checked:shadow-[0_0_0_3px_var(--color-surface),0_0_0_5px_var(--color-ink)]',
                    focusRing,
                  )}
                />
                <span
                  aria-hidden="true"
                  className="pointer-events-none absolute inset-0 hidden items-center justify-center text-surface peer-checked:flex"
                >
                  <Icon name="check" size={18} />
                </span>
              </span>
            ))}
          </div>
          {errors.colour ? (
            <p data-testid="party-form-error-colour" className="text-sm font-medium text-danger">
              {text('colour')}
            </p>
          ) : null}
        </fieldset>

        {added ? (
          <p
            role="status"
            data-testid="party-form-added"
            className="inline-flex items-center gap-2 text-base font-medium text-status-open"
          >
            <Icon name="check" size={18} />
            {t('parties.form.added', { name: added })}
          </p>
        ) : null}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="party-form-alert">
            {t('parties.form.error')} {problem}
          </Notice>
        ) : null}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end">
          <Button variant="quiet" disabled={busy} onClick={onClose} data-testid="party-form-cancel">
            {t('parties.form.cancel')}
          </Button>
          {party ? null : (
            <Button
              variant="secondary"
              loading={busy}
              onClick={() => void submit(true)}
              data-testid="party-form-save-another"
            >
              <span className="inline-flex items-center gap-2">
                <Icon name="plus" size={18} />
                {t('parties.form.addAnother')}
              </span>
            </Button>
          )}
          <Button type="submit" loading={busy} data-testid="party-form-save">
            <span className="inline-flex items-center gap-2">
              <Icon name="check" size={18} />
              {t(party ? 'parties.form.save' : 'parties.form.add')}
            </span>
          </Button>
        </div>
      </form>
    </Modal>
  );
}
