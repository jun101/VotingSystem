'use client';

import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { Button, Modal } from '@/components/ui';
import { createSecondRound } from '@/lib/api/browser';
import { ApiError, waitText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';

/**
 * Explains what a second round is and creates it: a new draft election with only the tied
 * ballot and the tied candidates, the same voters (new codes are needed). A second round that
 * already exists is not an error to the person: the page reads the results again and shows it.
 */
export function SecondRoundDialog({
  election,
  ballot,
  ballotTitle,
  names,
  onCreated,
  onExists,
  onCancel,
}: {
  election: string;
  ballot: string;
  ballotTitle: string;
  names: string[];
  onCreated: (newElection: string) => void;
  /** The API says one exists already: the page reads the results again. */
  onExists: () => void;
  onCancel: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    if (busy) return;

    setBusy(true);
    setError(null);

    try {
      onCreated((await createSecondRound(election, ballot)).id);
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      if (failure.status === 409 && failure.code === 'second_round_exists') {
        onExists();

        return;
      }

      setError(
        failure.status === 409 && failure.code === 'ballot_not_tied'
          ? t('results.second.notTied')
          : waitText(failure, tIfAny),
      );
      setBusy(false);
    }
  }

  const list = new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(names);

  return (
    <Modal
      title={t('results.second.title')}
      icon="flag"
      closeLabel={t('results.second.closeLabel')}
      onClose={onCancel}
      busy={busy}
      testId="second-round-dialog"
    >
      <div className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6">
        <p className="text-base text-ink-soft">
          {t('results.second.text', { ballot: ballotTitle, names: list })}
        </p>
        <ul className="flex flex-col gap-2.5 rounded-md bg-primary-soft px-4 py-3.5 text-base text-ink">
          {(['only', 'voters', 'codes'] as const).map((key) => (
            <li key={key} className="flex items-start gap-2.5">
              <span aria-hidden="true" className="mt-0.5 shrink-0 text-primary">
                <Icon name="check" size={18} />
              </span>
              {t(`results.second.points.${key}`)}
            </li>
          ))}
        </ul>
        {error ? (
          <p
            role="alert"
            data-testid="second-round-error"
            className="text-base font-medium text-danger"
          >
            {error}
          </p>
        ) : null}
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button
            variant="secondary"
            disabled={busy}
            onClick={onCancel}
            data-testid="second-round-cancel"
          >
            {t('results.second.cancel')}
          </Button>
          <Button
            data-autofocus
            variant="primary"
            loading={busy}
            onClick={() => void confirm()}
            data-testid="second-round-confirm"
          >
            {t('results.second.confirm')}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
