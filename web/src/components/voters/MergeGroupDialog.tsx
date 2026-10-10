'use client';

import { useId, useState } from 'react';
import { ConfirmDialog, Select } from '@/components/ui';
import { mergeGroup } from '@/lib/api/browser';
import { ApiError, errorText, fieldText } from '@/lib/api/errors';
import type { VoterGroup } from '@/lib/api/voters';
import { useI18n } from '@/lib/i18n/client';

/**
 * Merges a group into another: the person chooses the group that receives the voters, and the
 * sentence says what will happen. Nothing is chosen at first, so a tap never merges by accident.
 */
export function MergeGroupDialog({
  group,
  others,
  onMerged,
  onLocked,
  onCancel,
}: {
  group: Pick<VoterGroup, 'id' | 'name'>;
  /** The other groups of the election: the choices. */
  others: Pick<VoterGroup, 'id' | 'name'>[];
  onMerged: () => void;
  onLocked: () => void;
  onCancel: () => void;
}) {
  const { t, tIfAny } = useI18n();
  const [into, setInto] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const sentence = useId();

  async function confirm() {
    if (into === '') {
      setError(t('groups.merge.missing'));

      return;
    }

    setBusy(true);
    setError(null);

    try {
      await mergeGroup(group.id, into);
      onMerged();
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.code === 'election_voters_locked') return onLocked();

      const code = failure.status === 422 ? failure.fields.into?.[0] : undefined;

      setError(code ? fieldText('group.into', code, tIfAny) : errorText(failure, tIfAny));
      setBusy(false);
    }
  }

  return (
    <ConfirmDialog
      title={t('groups.merge.title')}
      confirmLabel={t('groups.merge.confirm')}
      cancelLabel={t('groups.merge.cancel')}
      busy={busy}
      error={error}
      describedBy={sentence}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'group-merge-dialog',
        confirm: 'group-merge-confirm',
        cancel: 'group-merge-cancel',
        error: 'group-merge-error',
      }}
    >
      <div className="flex flex-col gap-4">
        <p id={sentence}>{t('groups.merge.text', { name: group.name })}</p>
        <Select
          label={t('groups.merge.into')}
          value={into}
          onChange={(event) => {
            setInto(event.target.value);
            setError(null);
          }}
          disabled={busy}
          data-testid="group-merge-into"
        >
          <option value="">{t('groups.merge.choose')}</option>
          {others.map((other) => (
            <option key={other.id} value={other.id}>
              {other.name}
            </option>
          ))}
        </Select>
      </div>
    </ConfirmDialog>
  );
}
