'use client';

import { useRef, useState, type FormEvent } from 'react';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { createGroup, renameGroup } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { VoterGroup } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';
import { GROUP_MAX } from './voterForm';

/**
 * The group modal: a new group (`group` null) or the name of one to change. The name is
 * checked before the call; the API's answers (a name already used, a full election, a locked
 * one) come back beside the field or in one notice, and the modal stays open.
 */
export function GroupModal({
  election,
  group,
  onSaved,
  onLocked,
  onClose,
}: {
  election: string;
  group: Pick<VoterGroup, 'id' | 'name'> | null;
  onSaved: () => void;
  /** The election no longer lets groups change (409 `election_voters_locked`). */
  onLocked: () => void;
  onClose: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [name, setName] = useState(group?.name ?? '');
  const [code, setCode] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  async function submit(event: FormEvent) {
    event.preventDefault();

    const trimmed = name.trim();

    setProblem(null);

    if (trimmed === '' || Array.from(trimmed).length > GROUP_MAX) {
      setCode(trimmed === '' ? 'required' : 'max');
      input.current?.focus();

      return;
    }

    setCode(null);
    setBusy(true);

    try {
      if (group) await renameGroup(group.id, trimmed);
      else await createGroup(election, trimmed);

      onSaved();
      onClose();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setBusy(false);

      const own = failure.fields.name?.[0];

      if (failure.status === 422 && own) {
        setCode(own);
        input.current?.focus();

        return;
      }

      if (failure.code === 'election_voters_locked') onLocked();

      setProblem(errorText(failure, tIfAny));
    }
  }

  const title = t(group ? 'groups.form.titleEdit' : 'groups.form.titleNew');

  return (
    <Modal
      title={title}
      icon="people"
      closeLabel={t('groups.form.close')}
      onClose={onClose}
      busy={busy}
      testId="group-modal"
      closeTestId="group-modal-close"
    >
      <form
        noValidate
        onSubmit={(event) => void submit(event)}
        aria-label={title}
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <Input
          ref={input}
          label={t('groups.form.name')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          maxLength={GROUP_MAX + 20}
          autoComplete="off"
          required
          error={code ? fieldText('group.name', code, tIfAny) : undefined}
          errorTestId="group-form-error-name"
          data-testid="group-form-name"
          data-autofocus=""
        />

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="group-form-alert">
            {t('groups.form.error')} {problem}
          </Notice>
        ) : null}

        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <Button variant="quiet" disabled={busy} onClick={onClose} data-testid="group-form-cancel">
            {t('groups.form.cancel')}
          </Button>
          <Button type="submit" loading={busy} data-testid="group-form-save">
            {t(group ? 'groups.form.save' : 'groups.form.add')}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
