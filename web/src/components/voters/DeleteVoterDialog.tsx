'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { deleteVoter } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import type { Voter } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';

/**
 * Asks before a voter is deleted, naming the voter. The dialog stays open with the reason when
 * the API refuses; a voter that is already gone counts as deleted; an election that no longer
 * lets voters change is the page's to deal with (`onLocked`).
 */
export function DeleteVoterDialog({
  voter,
  onDeleted,
  onLocked,
  onCancel,
}: {
  voter: Pick<Voter, 'id' | 'full_name'>;
  onDeleted: () => void;
  onLocked: () => void;
  onCancel: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);

    try {
      await deleteVoter(voter.id);
      onDeleted();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_voters_locked') return onLocked();
      if (failure.status === 404) return onDeleted();

      setError(errorText(failure, tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('voters.delete.title')}
      confirmLabel={t('voters.delete.confirm')}
      cancelLabel={t('voters.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'voter-delete-dialog',
        confirm: 'voter-delete-confirm',
        cancel: 'voter-delete-cancel',
        error: 'voter-delete-error',
      }}
    >
      {t('voters.delete.text', { name: voter.full_name })}
    </ConfirmDialog>
  );
}
