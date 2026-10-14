'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { deleteGroup } from '@/lib/api/browser';
import { ApiError, errorText } from '@/lib/api/errors';
import type { VoterGroup } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';

/**
 * Asks before an empty group is deleted, naming it. A group that still has voters (409
 * `group_in_use`) is explained inside the dialog, which stays open; a group that is already gone
 * counts as deleted; a locked election is the page's to deal with (`onLocked`).
 */
export function DeleteGroupDialog({
  group,
  onDeleted,
  onLocked,
  onCancel,
}: {
  group: Pick<VoterGroup, 'id' | 'name'>;
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
      await deleteGroup(group.id);
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
      title={t('groups.delete.title')}
      confirmLabel={t('groups.delete.confirm')}
      cancelLabel={t('groups.delete.cancel')}
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'group-delete-dialog',
        confirm: 'group-delete-confirm',
        cancel: 'group-delete-cancel',
        error: 'group-delete-error',
      }}
    >
      {t('groups.delete.text', { name: group.name })}
    </ConfirmDialog>
  );
}
