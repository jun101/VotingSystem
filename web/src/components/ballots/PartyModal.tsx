'use client';

import { useRef, useState, type ChangeEvent, type DragEvent, type FormEvent } from 'react';
import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { usePickedCover } from '@/components/elections/CoverField';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { createParty, removePartyLogo, updateParty, uploadPartyLogo } from '@/lib/api/browser';
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
import { logoFileProblem, savePartyWithLogo, type LogoChange } from './partyLogo';

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
  onLocked,
  onClose,
}: {
  election: string;
  party: Party | null;
  onSaved: (party: Party) => void;
  /** The election is no longer a draft (409 `election_not_editable`): the page deals with it. */
  onLocked: () => void;
  onClose: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [draft, setDraft] = useState<PartyDraft>(() => (party ? draftOf(party) : EMPTY_DRAFT));
  const [errors, setErrors] = useState<PartyFieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [added, setAdded] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // The party the form works on: the one given, or the one just created when its logo failed
  // (the next save changes it instead of creating a second one).
  const [current, setCurrent] = useState<Party | null>(party);
  const { picked, pick, forget } = usePickedCover();
  const [removed, setRemoved] = useState(false);
  const [logoError, setLogoError] = useState<string | null>(null);
  const [over, setOver] = useState(false);
  const name = useRef<HTMLInputElement>(null);
  const logoInput = useRef<HTMLInputElement>(null);

  const storedLogo = !removed && current?.logo ? current.logo.md : null;
  const previewUrl = picked?.url ?? storedLogo;

  function chooseLogo(file: File | undefined) {
    if (!file) return;

    const problem = logoFileProblem(file);

    if (problem) {
      setLogoError(
        errorText(new ApiError(problem === 'file_too_large' ? 413 : 415, problem), tIfAny),
      );

      return;
    }

    setLogoError(null);
    setRemoved(false);
    pick(file);
  }

  function onLogoInput(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    event.target.value = '';
    chooseLogo(file);
  }

  function onDrop(event: DragEvent) {
    event.preventDefault();
    setOver(false);
    chooseLogo(event.dataTransfer.files[0]);
  }

  function removeLogo() {
    forget();
    setRemoved(true);
    setLogoError(null);
  }

  function logoChange(): LogoChange {
    if (picked) return { kind: 'set', file: picked.file };

    return removed ? { kind: 'remove' } : { kind: 'keep' };
  }

  function logoFailure(failure: ApiError): string {
    const code = failure.status === 422 ? failure.fields.file?.[0] : undefined;

    return code ? fieldText('party.logo', code, tIfAny) : errorText(failure, tIfAny);
  }

  function text(field: keyof PartyFieldErrors): string | undefined {
    const code = errors[field];

    return code ? fieldText(`party.${field}`, code, tIfAny) : undefined;
  }

  async function submit(another: boolean) {
    const found = validateParty(draft);

    setProblem(null);
    setAdded(null);
    setLogoError(null);
    setErrors(found);

    if (Object.keys(found).length > 0) {
      if (found.name || found.acronym) name.current?.focus();

      return;
    }

    setBusy(true);

    try {
      const result = await savePartyWithLogo(
        {
          create: createParty,
          update: updateParty,
          upload: uploadPartyLogo,
          remove: removePartyLogo,
        },
        { election, party: current },
        bodyOf(draft),
        logoChange(),
      );
      const saved = result.party;

      // The page shows the party at once, even when its logo failed.
      onSaved(saved);

      if (result.logoError) {
        setCurrent(saved);
        setLogoError(logoFailure(result.logoError));
        setBusy(false);

        return;
      }

      if (!another || party) {
        onClose();

        return;
      }

      setDraft(EMPTY_DRAFT);
      forget();
      setRemoved(false);
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

      if (failure.code === 'election_not_editable') onLocked();

      setProblem(errorText(failure, tIfAny));
    }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    void submit(false);
  }

  return (
    <Modal
      title={t(current ? 'parties.form.titleEdit' : 'parties.form.titleNew')}
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
        aria-label={t(current ? 'parties.form.titleEdit' : 'parties.form.titleNew')}
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

        <div
          data-testid="party-form-logo"
          onDragOver={(event) => {
            event.preventDefault();
            setOver(true);
          }}
          onDragLeave={() => setOver(false)}
          onDrop={onDrop}
          className="flex flex-col gap-2"
        >
          <input
            ref={logoInput}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            hidden
            tabIndex={-1}
            onChange={onLogoInput}
            data-testid="party-form-logo-input"
          />
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={() => logoInput.current?.click()}
              aria-label={t(previewUrl ? 'parties.form.logoChange' : 'parties.form.logoChoose')}
              data-testid="party-form-logo-drop"
              className={cx(
                'flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed bg-surface-alt text-primary transition-colors',
                over ? 'border-primary bg-primary-soft' : 'border-primary-line',
                focusRing,
              )}
            >
              {previewUrl ? (
                // A local or already optimised picture, shown inside its box.
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={previewUrl}
                  alt={t('parties.form.logoPreviewAlt')}
                  data-testid="party-form-logo-preview"
                  className="size-full object-contain"
                />
              ) : (
                <Icon name="image" size={22} />
              )}
            </button>
            <div className="flex min-w-0 flex-1 flex-col gap-1">
              <span className="text-md font-medium text-ink">{t('parties.form.logo')}</span>
              <span className="text-sm text-ink-soft">{t('parties.form.logoHelp')}</span>
              {previewUrl ? (
                <button
                  type="button"
                  onClick={removeLogo}
                  data-testid="party-form-logo-remove"
                  className={cx(
                    'w-fit rounded-full text-sm font-bold text-danger hover:underline',
                    focusRing,
                  )}
                >
                  {t('parties.form.logoRemove')}
                </button>
              ) : null}
            </div>
          </div>
          <p
            role="alert"
            data-testid={logoError ? 'party-form-error-logo' : undefined}
            className="text-sm font-medium text-danger"
          >
            {logoError}
          </p>
        </div>

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
          {current ? null : (
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
              {t(current ? 'parties.form.save' : 'parties.form.add')}
            </span>
          </Button>
        </div>
      </form>
    </Modal>
  );
}
