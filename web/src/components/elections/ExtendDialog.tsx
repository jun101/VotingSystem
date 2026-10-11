'use client';

import { useState, type FormEvent } from 'react';
import { Button, Input, Modal } from '@/components/ui';
import { extendElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, fieldText, waitText } from '@/lib/api/errors';
import { fromLocalInput, toLocalInput } from '@/lib/format/zonedTime';
import { useI18n } from '@/lib/i18n/client';

/**
 * Moves the end of a scheduled or open election later. The field starts at the current end, in
 * the election's time zone; the API judges the new one and its reason is shown inside the dialog,
 * which stays open.
 */
export function ExtendDialog({
  election,
  onExtended,
  onCancel,
  onStale,
}: {
  election: Pick<Election, 'id' | 'ends_at' | 'timezone'>;
  onExtended: (election: Election) => void;
  onCancel: () => void;
  /** The API says the election is no longer in the state the page showed: the page should read it again. */
  onStale?: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [value, setValue] = useState(() => toLocalInput(election.ends_at, election.timezone));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (busy) return;

    const read = fromLocalInput(value, election.timezone);

    if (!read) {
      setError(fieldText('election.ends_at', value.trim() === '' ? 'required' : 'invalid', tIfAny));

      return;
    }

    setBusy(true);
    setError(null);

    try {
      // The field has no seconds: the current end's seconds are kept, so moving the field by a day
      // moves the end by exactly a day, and an untouched field stays "not later" than the end.
      const seconds = new Date(election.ends_at).getUTCSeconds();
      const end = new Date(read.utc.getTime() + seconds * 1000);

      onExtended(await extendElection(election.id, end.toISOString().replace('.000Z', 'Z')));
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const code = failure.fields.ends_at?.[0];

      setError(code ? fieldText('election.ends_at', code, tIfAny) : waitText(failure, tIfAny));
      if (failure.status === 409) onStale?.();
      setBusy(false);
    }
  }

  return (
    <Modal
      title={t('elections.extend.title')}
      icon="clock"
      closeLabel={t('elections.extend.closeLabel')}
      onClose={onCancel}
      busy={busy}
      testId="extend-dialog"
    >
      <form
        onSubmit={submit}
        noValidate
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <p className="text-base text-ink-soft">{t('elections.extend.text')}</p>
        <Input
          data-autofocus
          data-testid="extend-date"
          type="datetime-local"
          label={t('elections.extend.label')}
          help={t('elections.extend.help', { zone: election.timezone })}
          value={value}
          onChange={(event) => setValue(event.target.value)}
        />
        {error ? (
          <p role="alert" data-testid="extend-error" className="text-base font-medium text-danger">
            {error}
          </p>
        ) : null}
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button
            variant="secondary"
            disabled={busy}
            onClick={onCancel}
            data-testid="extend-cancel"
          >
            {t('elections.extend.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={busy} data-testid="extend-confirm">
            {t('elections.extend.confirm')}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
