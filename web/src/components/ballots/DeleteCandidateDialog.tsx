'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { deleteCandidate } from '@/lib/api/browser';
import type { Candidate } from '@/lib/api/candidates';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { fullName } from './candidateForm';

/**
 * Asks before a candidate is deleted, naming it. The dialog stays open with the reason when the
 * API refuses; a candidate that is already gone counts as deleted; a locked election is the page's to deal with (`onLocked`).
 */
export function DeleteCandidateDialog({
  candidate,
  onDeleted,
  onLocked,
  onCancel,
}: {
  candidate: Candidate;
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
      await deleteCandidate(candidate.id);
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
      title={t('candidates.delete.title')}
      confirmLabel={t('candidates.delete.confirm')}
      cancelLabel={t('candidates.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'candidate-delete-dialog',
        confirm: 'candidate-delete-confirm',
        cancel: 'candidate-delete-cancel',
        error: 'candidate-delete-error',
      }}
    >
      {t('candidates.delete.text', { name: fullName(candidate) })}
    </ConfirmDialog>
  );
}
