'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import type { Ballot } from '@/lib/api/ballots';
import { deleteBallot } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * Asks before a ballot is deleted, naming it. The dialog stays open with the reason when the API
 * refuses; a ballot that is already gone counts as deleted; a locked election is the page's to
 * deal with (`onLocked`).
 */
export function DeleteBallotDialog({
  ballot,
  onDeleted,
  onLocked,
  onCancel,
}: {
  ballot: Pick<Ballot, 'id' | 'title'>;
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
      await deleteBallot(ballot.id);
      onDeleted();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_not_editable') return onLocked();
      if (failure.status === 404) return onDeleted();

      setError(errorText(failure, tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('ballots.delete.title')}
      confirmLabel={t('ballots.delete.confirm')}
      cancelLabel={t('ballots.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'ballot-delete-dialog',
        confirm: 'ballot-delete-confirm',
        cancel: 'ballot-delete-cancel',
        error: 'ballot-delete-error',
      }}
    >
      {t('ballots.delete.text', { title: ballot.title })}
    </ConfirmDialog>
  );
}
