'use client';

import { useId, useState } from 'react';
import { focusRing } from '@/components/admin/classes';
import { ConfirmDialog } from '@/components/ui';
import { scheduleElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { itemText, scheduleDialogState, type Item } from './checklist';

/**
 * Asks before a draft is scheduled. With warnings, they are listed and the confirm button waits
 * for a tick. A refusal of the API is shown in words and the dialog stays open, except when the
 * checklist itself has changed (`onStale`: the page reads it again).
 */
export function ScheduleDialog({
  election,
  warnings,
  onScheduled,
  onStale,
  onCancel,
}: {
  election: Pick<Election, 'id'>;
  warnings: Item[];
  onScheduled: (election: Election) => void;
  /** The server's checklist is not the one shown: read it again. */
  onStale: (code: string) => void;
  onCancel: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const listId = useId();
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const state = scheduleDialogState(warnings.length, accepted, busy);

  async function confirm() {
    setBusy(true);
    setError(null);

    try {
      onScheduled(await scheduleElection(election.id, accepted));
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setError(errorText(failure, tIfAny));
      setBusy(false);

      if (
        ['checklist_blocked', 'checklist_warnings', 'election_not_editable'].includes(failure.code)
      ) {
        onStale(failure.code);
      }
    }
  }

  return (
    <ConfirmDialog
      title={t('elections.checklist.schedule.title')}
      confirmLabel={t('elections.checklist.schedule.confirm')}
      cancelLabel={t('elections.checklist.schedule.cancel')}
      confirmVariant="primary"
      confirmDisabled={!state.canConfirm}
      busy={busy}
      error={error}
      describedBy={listId}
      onConfirm={confirm}
      onCancel={onCancel}
      testIds={{
        dialog: 'schedule-dialog',
        confirm: 'schedule-confirm',
        cancel: 'schedule-cancel',
        error: 'schedule-error',
      }}
    >
      <div id={listId} className="flex flex-col gap-3">
        <p>{t('elections.checklist.schedule.text')}</p>
        {state.needsTick ? (
          <>
            <p className="font-medium text-ink">{t('elections.checklist.schedule.warnings')}</p>
            <ul className="list-disc space-y-1 pl-5">
              {warnings.map((item, index) => (
                <li key={`${item.code}-${item.ballot?.id ?? index}`}>
                  {itemText(item, locale, t)}
                </li>
              ))}
            </ul>
            <label className="flex min-h-11 cursor-pointer items-start gap-3 text-md text-ink">
              <input
                type="checkbox"
                checked={accepted}
                onChange={(event) => setAccepted(event.target.checked)}
                disabled={busy}
                data-testid="schedule-accept-warnings"
                className={`mt-1 size-5 shrink-0 cursor-pointer accent-primary ${focusRing}`}
              />
              <span>{t('elections.checklist.schedule.accept')}</span>
            </label>
          </>
        ) : null}
      </div>
    </ConfirmDialog>
  );
}
