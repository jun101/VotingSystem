'use client';

import { useId, useRef, useState, type FormEvent } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { createVoter, updateVoter } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { Voter, VoterGroup } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import {
  EMAIL_MAX,
  EMPTY_DRAFT,
  GROUP_MAX,
  IDENTIFIER_MAX,
  NAME_MAX,
  PHONE_MAX,
  apiFieldErrors,
  bodyOf,
  draftOf,
  nextDraft,
  validateVoter,
  type VoterDraft,
  type VoterField,
  type VoterFieldErrors,
} from './voterForm';
import { suggestGroups } from './voterText';

/**
 * The voter modal: a new voter (`voter` null) or the values of one. The name is checked before
 * the call; the API's own answers (an identifier or an email already used, a full election, a
 * locked one) come back beside the field or in one notice, and the modal stays open. "Save and
 * add another" (creation only) keeps it open with the group kept, the rest cleared and the focus
 * on the name. The group is a text field with the election's groups as suggestions: a name that
 * does not exist creates the group.
 */
export function VoterModal({
  voter,
  election,
  groups,
  onSaved,
  onLocked,
  onClose,
}: {
  voter: Voter | null;
  election: string;
  groups: Pick<VoterGroup, 'id' | 'name'>[];
  /** A voter was saved: the page reads the list and the groups again. */
  onSaved: () => void;
  /** The election no longer lets voters change (409 `election_voters_locked`). */
  onLocked: () => void;
  onClose: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [draft, setDraft] = useState<VoterDraft>(() => (voter ? draftOf(voter) : EMPTY_DRAFT));
  const [errors, setErrors] = useState<VoterFieldErrors>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [added, setAdded] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const name = useRef<HTMLInputElement>(null);
  const suggestions = useId();

  function set(field: VoterField, value: string) {
    setDraft((current) => ({ ...current, [field]: value }));
  }

  function text(field: VoterField): string | undefined {
    const code = errors[field];

    return code ? fieldText(`voter.${field}`, code, tIfAny) : undefined;
  }

  function focusFirst(found: VoterFieldErrors) {
    const order: VoterField[] = ['full_name', 'group', 'identifier', 'email', 'phone'];
    const first = order.find((field) => found[field]);

    if (!first) return;

    document.querySelector<HTMLElement>(`[data-voter-field="${first}"]`)?.focus();
  }

  async function submit(another: boolean) {
    const found = validateVoter(draft);

    setProblem(null);
    setAdded(null);
    setErrors(found);

    if (Object.keys(found).length > 0) {
      focusFirst(found);

      return;
    }

    setBusy(true);

    try {
      const saved = voter
        ? await updateVoter(voter.id, bodyOf(draft))
        : await createVoter(election, bodyOf(draft));

      onSaved();

      if (!another || voter) {
        onClose();

        return;
      }

      setDraft(nextDraft(draft));
      setAdded(saved.full_name);
      setBusy(false);
      // The field is enabled again when the page has drawn; the focus goes there at once.
      name.current?.focus();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setBusy(false);

      const own = apiFieldErrors(failure.fields);

      if (failure.status === 422 && Object.keys(own).length > 0) {
        setErrors(own);
        focusFirst(own);

        return;
      }

      if (failure.code === 'election_voters_locked') onLocked();

      setProblem(errorText(failure, tIfAny));
    }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    void submit(false);
  }

  const title = t(voter ? 'voters.form.titleEdit' : 'voters.form.titleNew');

  return (
    <Modal
      title={title}
      icon="people"
      closeLabel={t('voters.form.close')}
      onClose={onClose}
      busy={busy}
      testId="voter-modal"
      closeTestId="voter-modal-close"
    >
      <form
        noValidate
        onSubmit={onSubmit}
        aria-label={title}
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <Input
          ref={name}
          label={t('voters.form.name')}
          help={t('voters.form.nameHelp')}
          value={draft.full_name}
          onChange={(event) => set('full_name', event.target.value)}
          maxLength={NAME_MAX + 20}
          autoComplete="off"
          required
          error={text('full_name')}
          errorTestId="voter-form-error-full_name"
          data-testid="voter-form-name"
          data-voter-field="full_name"
          data-autofocus=""
        />

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Input
            label={t('voters.form.group')}
            help={t('voters.form.groupHelp')}
            value={draft.group}
            onChange={(event) => set('group', event.target.value)}
            list={suggestions}
            maxLength={GROUP_MAX + 20}
            autoComplete="off"
            error={text('group')}
            errorTestId="voter-form-error-group"
            data-testid="voter-form-group"
            data-voter-field="group"
          />
          <datalist id={suggestions}>
            {suggestGroups(groups, draft.group).map((groupName) => (
              <option key={groupName} value={groupName} />
            ))}
          </datalist>
          <Input
            label={t('voters.form.identifier')}
            help={t('voters.form.identifierHelp')}
            value={draft.identifier}
            onChange={(event) => set('identifier', event.target.value)}
            maxLength={IDENTIFIER_MAX + 10}
            autoComplete="off"
            error={text('identifier')}
            errorTestId="voter-form-error-identifier"
            data-testid="voter-form-identifier"
            data-voter-field="identifier"
          />
          <Input
            label={t('voters.form.email')}
            help={t('voters.form.emailHelp')}
            type="email"
            inputMode="email"
            value={draft.email}
            onChange={(event) => set('email', event.target.value)}
            maxLength={EMAIL_MAX + 20}
            autoComplete="off"
            error={text('email')}
            errorTestId="voter-form-error-email"
            data-testid="voter-form-email"
            data-voter-field="email"
          />
          <Input
            label={t('voters.form.phone')}
            help={t('voters.form.phoneHelp')}
            type="tel"
            inputMode="tel"
            value={draft.phone}
            onChange={(event) => set('phone', event.target.value)}
            maxLength={PHONE_MAX + 10}
            autoComplete="off"
            error={text('phone')}
            errorTestId="voter-form-error-phone"
            data-testid="voter-form-phone"
            data-voter-field="phone"
          />
        </div>

        <Notice tone="info" className="flex-row items-start gap-3 p-3">
          <Icon name="lock" size={18} />
          <span>{t('voters.form.privacy')}</span>
        </Notice>

        {added ? (
          <p
            role="status"
            data-testid="voter-form-added"
            className="inline-flex items-center gap-2 text-base font-medium text-status-open"
          >
            <Icon name="check" size={18} />
            {t('voters.form.added', { name: added })}
          </p>
        ) : null}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="voter-form-alert">
            {t('voters.form.error')} {problem}
          </Notice>
        ) : null}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button variant="quiet" disabled={busy} onClick={onClose} data-testid="voter-form-cancel">
            {t('voters.form.cancel')}
          </Button>
          {voter ? null : (
            <Button
              variant="secondary"
              loading={busy}
              onClick={() => void submit(true)}
              data-testid="voter-form-save-another"
            >
              {t('voters.form.saveAnother')}
            </Button>
          )}
          <Button type="submit" loading={busy} data-testid="voter-form-save">
            <span className="inline-flex items-center gap-2">
              <Icon name="check" size={18} />
              {t('voters.form.save')}
            </span>
          </Button>
        </div>
      </form>
    </Modal>
  );
}
