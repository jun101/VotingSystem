'use client';

import { useState } from 'react';
import { Icon } from '@/components/admin/Icon';
import { saveBlob } from '@/components/codes/saveFile';
import { Panel } from '@/components/elections/Panel';
import { Button, ConfirmDialog } from '@/components/ui';
import {
  archiveElection,
  downloadElectionReport,
  unarchiveElection,
  unpublishElection,
} from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, waitText } from '@/lib/api/errors';
import type { ResultBallot } from '@/lib/api/results';
import { useI18n } from '@/lib/i18n/client';
import { PublishDialog } from './PublishDialog';

type Asking = 'publish' | 'unpublish' | null;

const LINK =
  'ui-control inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-primary bg-surface px-5 text-base font-semibold text-primary-hover shadow-1 hover:bg-primary-soft focus-visible:ring-4 focus-visible:ring-primary-soft focus-visible:outline-2 focus-visible:outline-primary';

/**
 * The publication card of A13: where the results stand (private, public, archived) and what can
 * be done: publish with a display choice, unpublish, archive, download the report, open the
 * public page. Every action goes through the API; the page is told the election that comes back.
 */
export function PublicationCard({
  election,
  ballots,
  onChanged,
  onStale,
  className,
}: {
  election: Election;
  ballots: ResultBallot[];
  onChanged: (election: Election) => void;
  /** The API says the state is not what the page showed: read it again. */
  onStale: () => void;
  className?: string;
}) {
  const { t, tIfAny } = useI18n();
  const [asking, setAsking] = useState<Asking>(null);
  const [busy, setBusy] = useState<'unpublish' | 'archive' | 'unarchive' | 'report' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const status = election.status;
  const published = status === 'published';

  function fail(caught: unknown) {
    const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

    setError(waitText(failure, tIfAny));
    if (failure.status === 409) onStale();
  }

  async function run(
    action: 'unpublish' | 'archive' | 'unarchive',
    call: (id: string) => Promise<Election>,
  ) {
    if (busy) return;

    setBusy(action);
    setError(null);

    try {
      onChanged(await call(election.id));
      setAsking(null);
    } catch (caught) {
      fail(caught);
    } finally {
      setBusy(null);
    }
  }

  async function report() {
    if (busy) return;

    setBusy('report');
    setError(null);

    try {
      const file = await downloadElectionReport(election.id);

      saveBlob(file.blob, file.name);
    } catch (caught) {
      const failure = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setError(
        failure.code === 'network' || failure.status >= 500
          ? t('publication.report.error')
          : waitText(failure, tIfAny),
      );
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <Panel
        tone="cover"
        icon="globe"
        title={t('publication.title')}
        id="publication-title"
        testId="publication-card"
        status={status}
        className={className}
        aside={
          published ? (
            <span className="inline-flex h-7.5 items-center gap-1.5 rounded-full bg-surface px-3 text-sm font-bold whitespace-nowrap text-status-published">
              <Icon name="eye" size={16} />
              {t('elections.status.published')}
            </span>
          ) : undefined
        }
      >
        <p className="text-base text-ink">
          {t(
            `publication.status.${status === 'published' || status === 'archived' ? status : 'closed'}`,
          )}
        </p>
        {published ? (
          <p className="inline-flex w-fit items-center gap-2 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-ink">
            <Icon name="list" size={16} />
            {t(`publication.chip.${election.results_display}`)}
          </p>
        ) : null}

        <div className="flex flex-col gap-2.5">
          {status === 'closed' ? (
            <Button
              variant="primary"
              className="w-full gap-2"
              onClick={() => setAsking('publish')}
              data-testid="publish-button"
            >
              <Icon name="globe" size={18} />
              {t('publication.publish.button')}
            </Button>
          ) : null}
          {published ? (
            <>
              <a
                href={`/results/${election.id}`}
                target="_blank"
                rel="noopener"
                data-testid="public-link"
                className={LINK}
              >
                <Icon name="open" size={18} />
                {t('publication.link.open')}
              </a>
              <Button
                variant="secondary"
                className="w-full gap-2"
                onClick={() => setAsking('unpublish')}
                data-testid="unpublish-button"
              >
                <Icon name="lock" size={18} />
                {t('publication.unpublish.button')}
              </Button>
            </>
          ) : null}
          <Button
            variant="secondary"
            className="w-full gap-2"
            loading={busy === 'report'}
            onClick={() => void report()}
            data-testid="report-download"
          >
            <Icon name="list" size={18} />
            {t('publication.report.button')}
          </Button>
          {status === 'archived' ? (
            <Button
              variant="secondary"
              className="w-full gap-2"
              loading={busy === 'unarchive'}
              onClick={() => void run('unarchive', unarchiveElection)}
              data-testid="unarchive-button"
            >
              <Icon name="archive" size={18} />
              {t('publication.archive.undo')}
            </Button>
          ) : (
            <>
              <Button
                variant="quiet"
                className="w-full gap-2"
                loading={busy === 'archive'}
                onClick={() => void run('archive', archiveElection)}
                data-testid="archive-button"
              >
                <Icon name="archive" size={18} />
                {t('publication.archive.button')}
              </Button>
              <p className="text-center text-sm text-ink-soft">{t('publication.archive.help')}</p>
            </>
          )}
        </div>

        {error && asking !== 'unpublish' ? (
          <p
            role="alert"
            data-testid="publication-error"
            className="text-base font-medium text-danger"
          >
            {error}
          </p>
        ) : null}
      </Panel>

      {asking === 'publish' ? (
        <PublishDialog
          election={election}
          ballots={ballots}
          onPublished={(next) => {
            setAsking(null);
            onChanged(next);
          }}
          onCancel={() => setAsking(null)}
          onStale={onStale}
        />
      ) : null}

      {asking === 'unpublish' ? (
        <ConfirmDialog
          title={t('publication.unpublish.title')}
          confirmLabel={t('publication.unpublish.confirm')}
          cancelLabel={t('publication.cancel')}
          confirmVariant="primary"
          busy={busy === 'unpublish'}
          error={error}
          onConfirm={() => void run('unpublish', unpublishElection)}
          onCancel={() => setAsking(null)}
          testIds={{
            dialog: 'unpublish-dialog',
            confirm: 'unpublish-confirm',
            cancel: 'unpublish-cancel',
            error: 'unpublish-error',
          }}
        >
          {t('publication.unpublish.text')}
        </ConfirmDialog>
      ) : null}
    </>
  );
}
