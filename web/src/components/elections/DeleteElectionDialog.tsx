'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { deleteElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * Asks before a draft is deleted, naming it. The dialog stays open with the reason when the API
 * refuses; `onDeleted` is called once the election is gone.
 */
export function DeleteElectionDialog({
  election,
  onDeleted,
  onCancel,
}: {
  election: Pick<Election, 'id' | 'title'>;
  onDeleted: () => void;
  onCancel: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);

    try {
      await deleteElection(election.id);
      onDeleted();
    } catch (caught) {
      setError(errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('elections.delete.title')}
      confirmLabel={t('elections.delete.confirm')}
      cancelLabel={t('elections.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'election-delete-dialog',
        confirm: 'election-delete-confirm',
        cancel: 'election-delete-cancel',
        error: 'election-delete-error',
      }}
    >
      {t('elections.delete.text', { title: election.title })}
    </ConfirmDialog>
  );
}
