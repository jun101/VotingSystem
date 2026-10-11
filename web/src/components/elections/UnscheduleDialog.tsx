'use client';

import { useState } from 'react';
import { ConfirmDialog } from '@/components/ui';
import { unscheduleElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/** Asks before a scheduled election goes back to a draft; the dialog stays open on a refusal. */
export function UnscheduleDialog({
  election,
  onUnscheduled,
  onCancel,
}: {
  election: Pick<Election, 'id'>;
  onUnscheduled: (election: Election) => void;
  onCancel: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);

    try {
      onUnscheduled(await unscheduleElection(election.id));
    } catch (caught) {
      setError(errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('elections.checklist.unschedule.title')}
      confirmLabel={t('elections.checklist.unschedule.confirm')}
      cancelLabel={t('elections.checklist.unschedule.cancel')}
      confirmVariant="primary"
      busy={busy}
      error={error}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'unschedule-dialog',
        confirm: 'unschedule-confirm',
        cancel: 'unschedule-cancel',
        error: 'unschedule-error',
      }}
    >
      {t('elections.checklist.unschedule.text')}
    </ConfirmDialog>
  );
}
