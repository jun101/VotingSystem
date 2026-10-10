'use client';

import { useRef, useState, type ChangeEvent, type DragEvent, type FormEvent } from 'react';
import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { usePickedCover } from '@/components/elections/CoverField';
import { PANEL_ASIDE, Panel } from '@/components/elections/Panel';
import { Button, Input, Modal, Notice, Select, Textarea } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import type { Ballot } from '@/lib/api/ballots';
import {
  createCandidate,
  removeCandidatePhoto,
  updateCandidate,
  uploadCandidatePhoto,
} from '@/lib/api/browser';
import type { Candidate, Sex } from '@/lib/api/candidates';
import { sexOf } from '@/lib/api/candidates';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';
import { seatsText } from './ballotText';
import { CandidateAvatar } from './CandidateAvatar';
import { PartyTag } from './CandidateRow';
import {
  apiFieldErrors,
  BIOGRAPHY_MAX,
  bodyOf,
  changesOf,
  charCount,
  draftOf,
  emptyDraft,
  fullName,
  NAME_MAX,
  SLOGAN_MAX,
  validateCandidate,
  type CandidateDraft,
  type CandidateField,
  type CandidateFieldErrors,
} from './candidateForm';
import { photoFileProblem, saveCandidateWithPhoto, type PhotoChange } from './candidatePhoto';
import { candidateCountText, counterText } from './candidateText';

const SEXES: readonly Sex[] = ['female', 'male'];

/**
 * The candidate modal (screen A07): a new candidate for a ballot (`candidate` null) or the
 * values of one, which moves to another ballot when the ballot is changed. Left, the cards
 * Identité and Candidature; right, the voter's card as it will look and the other candidates of
 * the chosen ballot. Names are checked before the call; the API's own answers come back beside
 * their field (422) or in one notice (a full ballot, a locked election), and the modal stays
 * open. "Save and add another" (creation only) keeps the ballot and the party, clears the rest
 * and puts the focus on the first name. `onSaved` receives the candidate as the API gives it back,
 * for the page to show in place.
 */
export function CandidateModal({
  ballots,
  parties,
  candidate,
  ballot,
  onSaved,
  onLocked,
  onClose,
}: {
  ballots: readonly Ballot[];
  parties: readonly Party[];
  candidate: Candidate | null;
  /** The ballot chosen when the modal opens for a new candidate. */
  ballot: string;
  onSaved: (candidate: Candidate) => void;
  /** The API said the election is no longer a draft. */
  onLocked: () => void;
  onClose: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const [draft, setDraft] = useState<CandidateDraft>(() =>
    candidate ? draftOf(candidate) : emptyDraft(ballot),
  );
  const [errors, setErrors] = useState<CandidateFieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [added, setAdded] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const first = useRef<HTMLInputElement>(null);
  const last = useRef<HTMLInputElement>(null);
  // The candidate the form works on: the one given, or the one just created when its photo
  // failed (the next save changes it instead of creating a second one).
  const [current, setCurrent] = useState<Candidate | null>(candidate);
  const { picked, pick, forget } = usePickedCover();
  const [removed, setRemoved] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [over, setOver] = useState(false);
  const photoInput = useRef<HTMLInputElement>(null);

  const chosen = ballots.find((item) => item.id === draft.ballot) ?? null;
  const party = parties.find((item) => item.id === draft.party) ?? null;
  const others = (chosen?.candidates ?? []).filter((item) => item.id !== current?.id);
  const name = fullName({ first_name: draft.firstName.trim(), last_name: draft.lastName.trim() });
  const slogan = draft.slogan.trim();
  const title = t(current ? 'candidates.form.titleEdit' : 'candidates.form.titleNew');
  const storedPhoto = !removed && current?.photo ? current.photo.md : null;
  const previewUrl = picked?.url ?? storedPhoto;

  function change(patch: Partial<CandidateDraft>) {
    setDraft({ ...draft, ...patch });
  }

  function choosePhoto(file: File | undefined) {
    if (!file) return;

    const found = photoFileProblem(file);

    if (found) {
      setPhotoError(errorText(new ApiError(found === 'file_too_large' ? 413 : 415, found), tIfAny));

      return;
    }

    setPhotoError(null);
    setRemoved(false);
    pick(file);
  }

  function onPhotoInput(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    event.target.value = '';
    choosePhoto(file);
  }

  function onDrop(event: DragEvent) {
    event.preventDefault();
    setOver(false);
    choosePhoto(event.dataTransfer.files[0]);
  }

  function removePhoto() {
    forget();
    setRemoved(true);
    setPhotoError(null);
  }

  function photoChange(): PhotoChange {
    if (picked) return { kind: 'set', file: picked.file };

    return removed ? { kind: 'remove' } : { kind: 'keep' };
  }

  function photoFailure(failure: ApiError): string {
    const code = failure.status === 422 ? failure.fields.file?.[0] : undefined;

    return code ? fieldText('candidate.photo', code, tIfAny) : errorText(failure, tIfAny);
  }

  function text(field: CandidateField): string | undefined {
    const code = errors[field];

    return code ? fieldText(`candidate.${field}`, code, tIfAny) : undefined;
  }

  async function submit(another: boolean) {
    const found = validateCandidate(draft);

    setProblem(null);
    setAdded(null);
    setPhotoError(null);
    setErrors(found);

    if (Object.keys(found).length > 0) {
      if (found.first_name) first.current?.focus();
      else if (found.last_name) last.current?.focus();

      return;
    }

    setBusy(true);

    try {
      const result = await saveCandidateWithPhoto(
        {
          create: createCandidate,
          update: updateCandidate,
          upload: uploadCandidatePhoto,
          remove: removeCandidatePhoto,
        },
        { ballot: draft.ballot, candidate: current },
        bodyOf(draft),
        changesOf(draft, current?.ballot ?? draft.ballot),
        photoChange(),
      );
      const saved = result.candidate;

      // The page shows the candidate at once, even when its photo failed.
      onSaved(saved);

      if (result.photoError) {
        setCurrent(saved);
        setRemoved(false);
        setPhotoError(photoFailure(result.photoError));
        setBusy(false);

        return;
      }

      if (!another || candidate) {
        onClose();

        return;
      }

      setDraft(emptyDraft(draft.ballot, draft.party));
      setCurrent(null);
      forget();
      setRemoved(false);
      setAdded(fullName(saved));
      setBusy(false);
      // The page has the new candidate; the focus is back on the first name at once.
      first.current?.focus();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setBusy(false);

      const own = apiFieldErrors(failure.fields);

      if (failure.status === 422 && Object.keys(own).length > 0) {
        setErrors(own);

        if (own.first_name) first.current?.focus();
        else if (own.last_name) last.current?.focus();

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
      title={title}
      icon="user"
      closeLabel={t('candidates.form.close')}
      onClose={onClose}
      busy={busy}
      wide
      testId="candidate-modal"
      closeTestId="candidate-modal-close"
    >
      <form
        noValidate
        onSubmit={onSubmit}
        aria-label={title}
        className="flex min-h-0 flex-1 flex-col"
      >
        <div className="flex flex-col gap-3.5 p-3.5 md:px-5 md:pt-4 md:pb-3.5 min-[1300px]:flex-row min-[1300px]:items-start">
          <div className="flex min-w-0 flex-1 flex-col gap-3.5">
            <Panel
              tone="calendar"
              icon="user"
              title={t('candidates.form.identity')}
              id="candidate-identity-title"
              plain
              bodyClassName="gap-3.5 pt-3.5 pb-4"
              aside={
                <span className={cx(PANEL_ASIDE, 'max-md:hidden')}>
                  {t('candidates.form.step1')}
                </span>
              }
            >
              <div className="flex flex-col gap-3.5 md:flex-row md:items-start md:gap-5">
                <div
                  data-testid="candidate-form-photo"
                  onDragOver={(event) => {
                    event.preventDefault();
                    setOver(true);
                  }}
                  onDragLeave={() => setOver(false)}
                  onDrop={onDrop}
                  className="flex flex-col gap-2 md:w-44 md:shrink-0"
                >
                  <input
                    ref={photoInput}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    hidden
                    tabIndex={-1}
                    onChange={onPhotoInput}
                    data-testid="candidate-form-photo-input"
                  />
                  <div className="flex items-center gap-3.5 md:flex-col md:items-stretch">
                    <button
                      type="button"
                      onClick={() => photoInput.current?.click()}
                      aria-label={t(
                        previewUrl ? 'candidates.form.photoChange' : 'candidates.form.photoChoose',
                      )}
                      data-testid="candidate-form-photo-drop"
                      className={cx(
                        'flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 border-dashed bg-surface-alt text-primary transition-colors md:size-28 md:self-center',
                        over ? 'border-primary bg-primary-soft' : 'border-primary-line',
                        focusRing,
                      )}
                    >
                      {previewUrl ? (
                        // A local or already optimised picture, shown inside its box.
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          src={previewUrl}
                          alt={t('candidates.form.photoPreviewAlt')}
                          data-testid="candidate-form-photo-preview"
                          className="size-full object-cover"
                        />
                      ) : (
                        <Icon name="image" size={28} />
                      )}
                    </button>
                    <div className="flex min-w-0 flex-1 flex-col gap-1 md:flex-none md:items-center md:text-center">
                      <span className="text-md font-medium text-ink">
                        {t('candidates.form.photo')}
                      </span>
                      <span className="text-sm text-ink-soft">
                        {t('candidates.form.photoHelp')}
                      </span>
                      {previewUrl ? (
                        <button
                          type="button"
                          onClick={removePhoto}
                          data-testid="candidate-form-photo-remove"
                          className={cx(
                            'w-fit rounded-full text-sm font-bold text-danger hover:underline',
                            focusRing,
                          )}
                        >
                          {t('candidates.form.photoRemove')}
                        </button>
                      ) : null}
                    </div>
                  </div>
                  <p
                    role="alert"
                    data-testid={photoError ? 'candidate-form-error-photo' : undefined}
                    className="text-sm font-medium text-danger"
                  >
                    {photoError}
                  </p>
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-3.5">
                  <div className="grid grid-cols-1 gap-x-5 gap-y-3.5 md:grid-cols-2">
                    <Input
                      ref={first}
                      label={t('candidates.form.firstName')}
                      value={draft.firstName}
                      onChange={(event) => change({ firstName: event.target.value })}
                      maxLength={NAME_MAX + 20}
                      autoComplete="off"
                      required
                      error={text('first_name')}
                      errorTestId="candidate-form-error-first-name"
                      data-testid="candidate-form-first-name"
                      data-autofocus=""
                    />
                    <Input
                      ref={last}
                      label={t('candidates.form.lastName')}
                      value={draft.lastName}
                      onChange={(event) => change({ lastName: event.target.value })}
                      maxLength={NAME_MAX + 20}
                      autoComplete="off"
                      required
                      error={text('last_name')}
                      errorTestId="candidate-form-error-last-name"
                      data-testid="candidate-form-last-name"
                    />
                  </div>

                  <fieldset className="flex min-w-0 flex-col gap-2">
                    <legend className="mb-1.5 text-xs font-bold tracking-wider text-ink-soft uppercase">
                      {t('candidates.form.sex')}
                    </legend>
                    <div className="grid grid-cols-2 gap-3">
                      {SEXES.map((sex) => (
                        <label
                          key={sex}
                          className="relative flex h-14 min-w-0 items-center gap-2.5 rounded-lg px-2.5 md:gap-3 md:px-3.5"
                        >
                          <input
                            type="radio"
                            name="candidate-sex"
                            value={sex}
                            checked={draft.sex === sex}
                            onChange={() => change({ sex })}
                            data-testid={`candidate-form-sex-${sex}`}
                            className={cx(
                              'peer absolute inset-0 size-full cursor-pointer appearance-none rounded-lg border-2 border-line transition-colors checked:border-primary checked:bg-primary-soft',
                              focusRing,
                            )}
                          />
                          <CandidateAvatar
                            sex={sex}
                            size="size-9"
                            iconSize={20}
                            className="pointer-events-none relative"
                          />
                          <span className="pointer-events-none relative min-w-0 truncate text-md font-medium text-ink peer-checked:font-bold peer-checked:text-status-scheduled">
                            {t(`candidates.sex.${sex}`)}
                          </span>
                          <span
                            aria-hidden="true"
                            className="pointer-events-none relative ml-auto hidden text-status-scheduled md:peer-checked:inline-flex"
                          >
                            <Icon name="check" size={18} />
                          </span>
                        </label>
                      ))}
                    </div>
                    {errors.sex ? (
                      <p
                        data-testid="candidate-form-error-sex"
                        className="text-sm font-medium text-danger"
                      >
                        {text('sex')}
                      </p>
                    ) : (
                      <p className="text-sm text-ink-soft">{t('candidates.form.sexHelp')}</p>
                    )}
                  </fieldset>
                </div>
              </div>
            </Panel>

            <Panel
              tone="settings"
              icon="flag"
              title={t('candidates.form.candidature')}
              id="candidate-candidature-title"
              plain
              bodyClassName="gap-3.5 pt-3.5 pb-4"
              aside={
                <span className={cx(PANEL_ASIDE, 'max-md:hidden')}>
                  {t('candidates.form.step2')}
                </span>
              }
            >
              <div className="grid grid-cols-1 gap-x-5 gap-y-3.5 md:grid-cols-2">
                <Select
                  label={t('candidates.form.ballot')}
                  value={draft.ballot}
                  onChange={(event) => change({ ballot: event.target.value })}
                  error={text('ballot')}
                  errorTestId="candidate-form-error-ballot"
                  data-testid="candidate-form-ballot"
                >
                  {ballots.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.title}
                    </option>
                  ))}
                </Select>
                <Select
                  label={t('candidates.form.party')}
                  value={draft.party}
                  onChange={(event) => change({ party: event.target.value })}
                  error={text('party')}
                  errorTestId="candidate-form-error-party"
                  data-testid="candidate-form-party"
                >
                  <option value="">{t('candidates.form.noParty')}</option>
                  {parties.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
                </Select>
              </div>

              <div className="flex flex-col gap-1">
                <Input
                  label={t('candidates.form.slogan')}
                  value={draft.slogan}
                  onChange={(event) => change({ slogan: event.target.value })}
                  maxLength={SLOGAN_MAX + 20}
                  autoComplete="off"
                  error={text('slogan')}
                  errorTestId="candidate-form-error-slogan"
                  data-testid="candidate-form-slogan"
                />
                <span
                  data-testid="candidate-form-slogan-count"
                  className={cx(
                    'self-end text-sm',
                    charCount(slogan) > SLOGAN_MAX ? 'font-bold text-danger' : 'text-ink-soft',
                  )}
                >
                  {counterText(charCount(slogan), SLOGAN_MAX, t)}
                </span>
              </div>

              <div className="flex flex-col gap-1">
                <Textarea
                  label={t('candidates.form.biography')}
                  value={draft.biography}
                  onChange={(event) => change({ biography: event.target.value })}
                  rows={2}
                  className="min-h-16 resize-y"
                  error={text('biography')}
                  errorTestId="candidate-form-error-biography"
                  data-testid="candidate-form-biography"
                />
                <span
                  data-testid="candidate-form-biography-count"
                  className={cx(
                    'self-end text-sm',
                    charCount(draft.biography.trim()) > BIOGRAPHY_MAX
                      ? 'font-bold text-danger'
                      : 'text-ink-soft',
                  )}
                >
                  {counterText(charCount(draft.biography.trim()), BIOGRAPHY_MAX, t)}
                </span>
              </div>
            </Panel>
          </div>

          <aside className="flex w-full min-w-0 shrink-0 flex-col gap-3.5 min-[1300px]:w-[22rem]">
            <Panel
              tone="cover"
              icon="eye"
              title={t('candidates.preview.title')}
              id="candidate-preview-title"
              plain
              bodyClassName="items-center gap-2 pt-3.5 pb-4"
            >
              <div
                data-testid="candidate-preview"
                className="w-full max-w-66 rounded-xl bg-ink p-2.5 shadow-3"
              >
                <div className="flex min-h-56 flex-col gap-3 rounded-lg bg-canvas px-3 pt-3.5 pb-4">
                  <div aria-hidden="true" className="h-1.5 overflow-hidden rounded-full bg-line">
                    <i className="block h-full w-1/3 bg-primary" />
                  </div>
                  <p className="font-display text-md leading-tight font-extrabold break-words text-ink">
                    {chosen
                      ? t('candidates.preview.seats', {
                          title: chosen.title,
                          seats: seatsText(chosen.seats, locale, t),
                        })
                      : ''}
                  </p>
                  <div className="flex min-w-0 flex-col items-center gap-2 rounded-md border-2 border-primary bg-surface px-3 py-4 text-center shadow-2">
                    <CandidateAvatar
                      sex={draft.sex}
                      size="size-20"
                      iconSize={42}
                      testId="candidate-preview-avatar"
                      photo={previewUrl}
                      photoTestId="candidate-preview-photo"
                    />
                    <b
                      data-testid="candidate-preview-name"
                      className={cx(
                        'max-w-full font-display text-lg leading-tight font-extrabold break-words',
                        name === '' ? 'text-ink-soft' : 'text-ink',
                      )}
                    >
                      {name === '' ? t('candidates.preview.name') : name}
                    </b>
                    <span className="inline-flex max-w-full items-center rounded-full bg-primary-soft px-2.5 py-1">
                      <PartyTag party={party} textClass="text-xs font-bold text-status-scheduled" />
                    </span>
                    {slogan !== '' ? (
                      <q
                        data-testid="candidate-preview-slogan"
                        className="max-w-full text-base break-words text-ink-soft"
                      >
                        {slogan}
                      </q>
                    ) : null}
                    <span
                      aria-hidden="true"
                      className="mt-1 flex h-10 w-full items-center justify-center rounded-full bg-primary text-base font-bold text-surface"
                    >
                      {t('candidates.preview.chosen')}
                    </span>
                  </div>
                </div>
              </div>
              <span className="text-sm text-ink-soft">{t('candidates.preview.hint')}</span>
            </Panel>

            <Panel
              tone="facts"
              icon="people"
              title={t('candidates.others.title')}
              id="candidate-others-title"
              testId="candidate-others"
              plain
              bodyClassName="gap-1 pt-3 pb-4"
              aside={
                <span className={PANEL_ASIDE}>{candidateCountText(others.length, locale, t)}</span>
              }
            >
              {others.length === 0 ? (
                <p className="text-base text-ink-soft">{t('candidates.others.empty')}</p>
              ) : (
                <ul className="flex max-h-44 flex-col gap-0.5 overflow-y-auto min-[1300px]:max-h-40">
                  {others.map((other, index) => (
                    <li
                      key={other.id}
                      data-testid={`candidate-other-${index + 1}`}
                      className="flex min-w-0 items-center gap-3 rounded-lg px-1.5 py-1"
                    >
                      <CandidateAvatar sex={sexOf(other)} size="size-9" iconSize={20} />
                      <span className="flex min-w-0 flex-col">
                        <b className="truncate text-md font-medium text-ink">{fullName(other)}</b>
                        <PartyTag party={parties.find((item) => item.id === other.party) ?? null} />
                      </span>
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-1 flex items-start gap-2 rounded-lg bg-canvas px-3 py-2 text-sm text-ink-2">
                <span className="mt-0.5 shrink-0 text-primary">
                  <Icon name="shuffle" size={16} />
                </span>
                {t('candidates.others.hint')}
              </p>
            </Panel>
          </aside>
        </div>

        <div className="sticky bottom-0 z-10 mt-auto flex flex-col gap-2 border-t border-line bg-surface px-3.5 py-3 md:px-5">
          {added ? (
            <p
              role="status"
              data-testid="candidate-form-added"
              className="inline-flex items-center gap-2 text-base font-medium text-status-open"
            >
              <Icon name="check" size={18} />
              {t('candidates.form.added', { name: added })}
            </p>
          ) : null}

          {problem ? (
            <Notice tone="danger" role="alert" data-testid="candidate-form-alert">
              {t('candidates.form.error')} {problem}
            </Notice>
          ) : null}

          <div className="flex flex-col-reverse gap-2.5 sm:flex-row sm:items-center sm:justify-end">
            <p className="hidden text-sm text-ink-soft min-[1100px]:block min-[1100px]:flex-1">
              {t('candidates.form.hint')}
            </p>
            <Button
              variant="quiet"
              disabled={busy}
              onClick={onClose}
              data-testid="candidate-form-cancel"
            >
              {t('candidates.form.cancel')}
            </Button>
            {current ? null : (
              <Button
                variant="secondary"
                loading={busy}
                onClick={() => void submit(true)}
                data-testid="candidate-form-save-another"
              >
                <span className="inline-flex items-center gap-2">
                  <Icon name="plus" size={18} />
                  {t('candidates.form.addAnother')}
                </span>
              </Button>
            )}
            <Button type="submit" loading={busy} data-testid="candidate-form-save">
              <span className="inline-flex items-center gap-2">
                <Icon name="check" size={18} />
                {t(current ? 'candidates.form.save' : 'candidates.form.add')}
              </span>
            </Button>
          </div>
        </div>
      </form>
    </Modal>
  );
}
