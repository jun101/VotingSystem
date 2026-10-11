'use client';

import { useState, type FormEvent } from 'react';
import { Button, Modal, Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { publishElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, waitText } from '@/lib/api/errors';
import type { ResultBallot } from '@/lib/api/results';
import { joinList, pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { smallBallots } from './resultsModel';

type Display = 'full' | 'winners';

/**
 * Publishes the results of a closed election (A13). The person chooses what the public page shows
 * (the election's own setting is the first choice); a ballot with fewer than 3 eligible voters
 * is named in a warning, which does not block. A refusal stays in the dialog with its reason.
 */
export function PublishDialog({
  election,
  ballots,
  onPublished,
  onCancel,
  onStale,
}: {
  election: Pick<Election, 'id' | 'title' | 'results_display'>;
  ballots: ResultBallot[];
  onPublished: (election: Election) => void;
  onCancel: () => void;
  /** The API says the election is no longer closed: the page should read it again. */
  onStale: () => void;
}) {
  const { t, tIfAny, locale } = useI18n();
  const [display, setDisplay] = useState<Display>(election.results_display);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const small = smallBallots(ballots);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (busy) return;

    setBusy(true);
    setError(null);

    try {
      onPublished(await publishElection(election.id, display));
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      let text = waitText(failure, tIfAny);

      if (failure.code === 'results_check_failed') {
        const named = ballots
          .filter((ballot) => failure.ballots.includes(ballot.id))
          .map((ballot) => ballot.title);

        if (named.length > 0) {
          text = `${text} ${t('publication.publish.failedBallots', { ballots: joinList(locale, named) })}`;
        }
      }

      setError(text);
      if (failure.status === 409 && failure.code !== 'results_check_failed') onStale();
      setBusy(false);
    }
  }

  const options: { value: Display; label: string; help: string; testId: string }[] = [
    {
      value: 'full',
      label: t('publication.publish.full'),
      help: t('publication.publish.fullHelp'),
      testId: 'publish-display-full',
    },
    {
      value: 'winners',
      label: t('publication.publish.winners'),
      help: t('publication.publish.winnersHelp'),
      testId: 'publish-display-winners',
    },
  ];

  return (
    <Modal
      title={t('publication.publish.title')}
      icon="globe"
      closeLabel={t('publication.closeLabel')}
      onClose={onCancel}
      busy={busy}
      testId="publish-dialog"
    >
      <form
        onSubmit={submit}
        noValidate
        className="flex flex-col gap-4 p-4 md:px-6 md:pt-5 md:pb-6"
      >
        <p className="text-base text-ink-soft">
          {t('publication.publish.text', { title: election.title })}
        </p>
        <fieldset className="flex flex-col gap-2.5">
          <legend className="mb-1 text-base font-bold text-ink">
            {t('publication.publish.legend')}
          </legend>
          {options.map((option) => (
            <label
              key={option.value}
              className={cx(
                'flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border p-3.5',
                'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary',
                display === option.value
                  ? 'border-primary bg-primary-soft'
                  : 'border-line bg-surface hover:bg-surface-alt',
              )}
            >
              <input
                type="radio"
                name="publish-display"
                value={option.value}
                checked={display === option.value}
                onChange={() => setDisplay(option.value)}
                data-testid={option.testId}
                data-autofocus={option.value === election.results_display ? '' : undefined}
                className="mt-1 size-5 shrink-0 accent-primary"
              />
              <span className="flex min-w-0 flex-col">
                <b className="text-base font-bold text-ink">{option.label}</b>
                <span className="text-sm text-ink-soft">{option.help}</span>
              </span>
            </label>
          ))}
        </fieldset>
        {small.length > 0 ? (
          <Notice tone="warm" data-testid="publish-small-group">
            {t(`publication.publish.smallGroup.${pluralForm(locale, small.length)}`, {
              ballots: joinList(
                locale,
                small.map((ballot) => ballot.title),
              ),
            })}
          </Notice>
        ) : null}
        {error ? (
          <p role="alert" data-testid="publish-error" className="text-base font-medium text-danger">
            {error}
          </p>
        ) : null}
        <div className="flex flex-col-reverse gap-3 sm:flex-row sm:flex-wrap sm:justify-end sm:gap-2">
          <Button
            variant="secondary"
            disabled={busy}
            onClick={onCancel}
            data-testid="publish-cancel"
          >
            {t('publication.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={busy} data-testid="publish-confirm">
            {t('publication.publish.confirm')}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
