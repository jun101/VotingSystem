'use client';

import { useState, type FormEvent } from 'react';
import { Button, Input, Modal, Notice } from '@/components/ui';
import { closeElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/** Whether what was typed is the title (case and surrounding spaces ignored, accents counted). */
export function matchesTitle(typed: string, title: string): boolean {
  return typed.trim().toLocaleLowerCase() === title.trim().toLocaleLowerCase();
}

/**
 * Closes an open election early. The person types its title; the confirm button waits for it. The
 * dialog stays open with the reason when the API refuses.
 */
export function CloseDialog({
  election,
  onClosed,
  onCancel,
}: {
  election: Pick<Election, 'id' | 'title'>;
  onClosed: (election: Election) => void;
  onCancel: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [typed, setTyped] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const ready = typed.trim() !== '' && matchesTitle(typed, election.title);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!ready || busy) return;

    setBusy(true);
    setError(null);

    try {
      onClosed(await closeElection(election.id, typed.trim()));
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const code = failure.fields.confirmation?.[0];

      setError(code ? fieldText('closing.confirmation', code, tIfAny) : errorText(failure, tIfAny));
      setBusy(false);
    }
  }

  return (
    <Modal
      title={t('elections.close.title')}
      icon="lock"
      closeLabel={t('elections.close.closeLabel')}
      onClose={onCancel}
      busy={busy}
      testId="close-dialog"
    >
      <form
        onSubmit={submit}
        noValidate
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <Notice tone="warm">{t('elections.close.warning')}</Notice>
        <p className="text-base text-ink-soft">
          {t('elections.close.text', { title: election.title })}
        </p>
        <Input
          data-autofocus
          data-testid="close-confirmation"
          label={t('elections.close.label')}
          value={typed}
          onChange={(event) => setTyped(event.target.value)}
          autoComplete="off"
          autoCorrect="off"
          spellCheck={false}
          maxLength={200}
        />
        {error ? (
          <p role="alert" data-testid="close-error" className="text-base font-medium text-danger">
            {error}
          </p>
        ) : null}
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button variant="secondary" disabled={busy} onClick={onCancel} data-testid="close-cancel">
            {t('elections.close.cancel')}
          </Button>
          <Button
            type="submit"
            variant="danger"
            loading={busy}
            disabled={!ready}
            data-testid="close-confirm"
          >
            {t('elections.close.confirm')}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
