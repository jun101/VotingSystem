'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { deleteParty } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import type { Party } from '@/lib/api/parties';
import { useI18n } from '@/lib/i18n/client';

/**
 * Asks before a party is deleted, naming it and saying its candidates stay (independent). The
 * dialog stays open with the reason when the API refuses; a party that is already gone counts
 * as deleted.
 */
export function DeletePartyDialog({
  party,
  onDeleted,
  onCancel,
}: {
  party: Pick<Party, 'id' | 'name'>;
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
      await deleteParty(party.id);
      onDeleted();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.status === 404) return onDeleted();

      setError(errorText(failure, tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('parties.delete.title')}
      confirmLabel={t('parties.delete.confirm')}
      cancelLabel={t('parties.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'party-delete-dialog',
        confirm: 'party-delete-confirm',
        cancel: 'party-delete-cancel',
        error: 'party-delete-error',
      }}
    >
      {t('parties.delete.text', { name: party.name })}
    </ConfirmDialog>
  );
}
