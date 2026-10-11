'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState, useTransition } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Panel } from '@/components/elections/Panel';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { LiveDot } from '@/components/motion';
import { Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import {
  downloadGeneratedFile,
  fetchCredentials,
  fetchGeneratedFiles,
  startCredentialRun,
} from '@/lib/api/browser';
import {
  lineKey,
  type CredentialLine,
  type CredentialState,
  type GeneratedFile,
  type RunBody,
} from '@/lib/api/credentials';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import {
  canGenerateAll,
  codesLocked,
  downloadsOf,
  isQueued,
  isRunning,
  mergeFiles,
  outcomeOf,
  percentOf,
  runBodyOf,
} from './codeRules';
import { GroupCard } from './GroupCard';
import { LostSlipDialog } from './LostSlipDialog';
import { saveBlob } from './saveFile';

/** While a file is queued the page reads the state this often. */
export const POLL_MS = 1500;

/** The text colour of the badge on the header band, by status (as on the voters page). */
const BADGE_TEXT: Record<ElectionStatus, string> = {
  draft: 'text-status-draft',
  scheduled: 'text-status-scheduled',
  open: 'text-status-open',
  closed: 'text-ink-soft',
  published: 'text-status-published',
  archived: 'text-ink-soft',
};

const CHIP =
  'inline-flex h-8.5 items-center gap-2 rounded-full border border-glass-line bg-glass pr-3.5 pl-2.5 text-base font-medium';

const ADD =
  'ui-control lift-sm inline-flex h-12 items-center justify-center gap-2 rounded-full border border-accent bg-accent pr-5.5 pl-4 text-md font-bold text-deep shadow-button max-md:w-full disabled:cursor-not-allowed disabled:opacity-60 ' +
  focusRing;

const GLASS =
  'ui-control inline-flex h-12 items-center justify-center gap-2 rounded-full border border-glass-line bg-glass pr-5 pl-4 text-md font-semibold text-surface hover:bg-glass-line max-md:w-full ' +
  focusRing;

/**
 * Screen A11: the access codes of one election. A header band with the status and the two main
 * actions (generate every missing code, replace a lost slip), the totals, then one card per group
 * with its count, its generate button and its PDF. The state is read again every 1.5 seconds while
 * a file is being written. Reading is always allowed; making a code follows the election's status.
 * A code is never held here: the only place one is shown is the lost-slip dialog.
 */
export function CodesPage({
  election,
  initialState,
  initialFiles,
}: {
  election: Election;
  initialState: CredentialState;
  initialFiles: GeneratedFile[];
}) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const [, startTransition] = useTransition();

  const [state, setState] = useState(initialState);
  const [files, setFiles] = useState(initialFiles);
  // The files this page saw being written: the result is about them.
  const [watched, setWatched] = useState<ReadonlySet<string>>(
    () => new Set(initialFiles.filter(isQueued).map((file) => file.id)),
  );
  const [problem, setProblem] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [downloading, setDownloading] = useState<string | null>(null);
  const [lost, setLost] = useState(false);
  const [tick, setTick] = useState(0);
  const [lockedAt, setLockedAt] = useState<typeof election | null>(null);

  const lockedSeen = lockedAt === election;
  const locked = lockedSeen || codesLocked(election.status);
  const running = isRunning(state, files);
  const outcome = useMemo(() => outcomeOf(files, watched), [files, watched]);

  /** The state and the files, read again. */
  async function refresh() {
    const [nextState, nextFiles] = await Promise.all([
      fetchCredentials(election.id),
      fetchGeneratedFiles(election.id),
    ]);

    setState(nextState);
    setFiles(nextFiles);
    setWatched((now) => {
      const queued = nextFiles.filter(isQueued).filter((file) => !now.has(file.id));

      return queued.length === 0 ? now : new Set([...now, ...queued.map((file) => file.id)]);
    });
  }

  function failure(caught: unknown) {
    const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

    if (error.code === 'election_codes_locked') {
      void becameLocked();

      return;
    }

    setProblem(errorText(error, tIfAny));
  }

  /** The election no longer lets codes change: the notice, the real state and status. */
  async function becameLocked() {
    setProblem(errorText(new ApiError(409, 'election_codes_locked'), tIfAny));
    setLost(false);
    setLockedAt(election);
    startTransition(() => router.refresh());

    try {
      await refresh();
    } catch {
      // The notice stands; the page is read again with the refresh above.
    }
  }

  // While something is being written: read again, one request after the other.
  useEffect(() => {
    if (!running) return;

    const timer = window.setTimeout(() => {
      refresh().then(
        () => setTick((n) => n + 1),
        (caught: unknown) => {
          // A network blip is tried again; a refusal that will not change stops the reading.
          if (caught instanceof ApiError && [401, 403, 404].includes(caught.status)) {
            failure(caught);

            return;
          }

          setTick((n) => n + 1);
        },
      );
    }, POLL_MS);

    return () => window.clearTimeout(timer);
    // `refresh` reads only the election and setters.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [running, tick]);

  async function run(body: RunBody) {
    if (starting || running) return;

    setStarting(true);
    setProblem(null);

    try {
      const started = await startCredentialRun(election.id, body);

      setFiles((now) => mergeFiles(now, started.files));
      setWatched((now) => new Set([...now, ...started.files.map((file) => file.id)]));
      void refresh().catch(() => undefined);
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : null;

      failure(caught);

      if (error?.code === 'run_in_progress' || error?.code === 'nothing_to_generate') {
        void refresh().catch(() => undefined);
      }
    }

    setStarting(false);
  }

  async function download(file: GeneratedFile) {
    if (downloading) return;

    setDownloading(file.id);
    setProblem(null);

    try {
      const saved = await downloadGeneratedFile(file.id);

      saveBlob(saved.blob, saved.name);
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');

      setProblem(`${t('codes.group.downloadFailed')} ${errorText(error, tIfAny)}`);
    }

    setDownloading(null);
  }

  function lostClosed(changed: boolean) {
    setLost(false);

    if (changed) void refresh().catch(() => undefined);
  }

  const percent = percentOf(state.with_code, state.voters);
  const missing = state.without_code;

  return (
    <div data-testid="codes-page" className="flex flex-col gap-4.5">
      <Link
        href={`/admin/elections/${election.id}`}
        data-testid="codes-back"
        className={cx(
          'ui-control inline-flex min-h-11 w-fit items-center gap-2 rounded-full pr-3 text-md font-medium text-ink-soft hover:text-ink',
          focusRing,
        )}
      >
        <Icon name="left" size={18} />
        {t('codes.back')}
      </Link>

      <section
        data-testid="codes-hero"
        data-status={election.status}
        aria-labelledby="codes-title"
        className="election-hero cover-sweep relative flex min-h-32 flex-wrap items-end gap-x-6 gap-y-4 rounded-xl px-4 py-5 text-surface shadow-2 md:px-7.5 [@media(max-height:820px)]:min-h-24 [@media(max-height:820px)]:py-3.5"
      >
        <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
          <Icon name={COVER_ICONS[election.status]} size={210} />
        </span>
        <div className="relative z-10 min-w-0 flex-[1_1_22rem]">
          <span
            data-testid="codes-badge"
            className={cx(
              'inline-flex min-h-7 items-center gap-2 rounded-full bg-surface/95 px-3.5 py-1 text-sm font-bold',
              BADGE_TEXT[election.status],
            )}
          >
            {election.status === 'open' ? (
              <LiveDot />
            ) : (
              <Icon name={STATUS_ICONS[election.status]} size={16} />
            )}
            <span data-status={election.status}>
              {election.status === 'draft'
                ? t('codes.badgeDraft')
                : t(`elections.status.${election.status}`)}
            </span>
          </span>
          <h2
            id="codes-title"
            data-testid="codes-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('codes.title')}
          </h2>
          <p className="line-clamp-2 text-md break-words text-surface">{election.title}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span className={CHIP}>
              <Icon name="people" size={18} />
              {t(`codes.group.voters.${pluralForm(locale, state.voters)}`, { count: state.voters })}
            </span>
            <span className={CHIP}>
              <Icon name="qr" size={18} />
              {t('codes.totals.progress', { count: state.with_code, total: state.voters })}
            </span>
          </div>
        </div>

        {locked ? null : (
          <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
            <button
              type="button"
              data-testid="codes-lost"
              onClick={() => setLost(true)}
              className={GLASS}
            >
              <Icon name="user" size={18} />
              {t('codes.lost.open')}
            </button>
            <button
              type="button"
              data-testid="codes-generate-all"
              disabled={!canGenerateAll(state, running) || starting}
              onClick={() => void run({ scope: 'all' })}
              className={ADD}
            >
              <Icon name="qr" size={20} />
              {missing > 0
                ? t(`codes.generateAllCount.${pluralForm(locale, missing)}`, { count: missing })
                : t('codes.generateAll')}
            </button>
          </div>
        )}
      </section>

      {problem ? (
        <Notice tone="danger" role="alert" data-testid="codes-notice">
          {problem}
        </Notice>
      ) : null}

      {locked ? (
        <Notice tone="info" data-testid="codes-locked-notice">
          {t('codes.locked')}
        </Notice>
      ) : null}

      {running ? (
        <Notice tone="info" role="status" data-testid="codes-run-status">
          <span className="flex items-center gap-2">
            <LiveDot />
            {t('codes.run.status')}
          </span>
        </Notice>
      ) : outcome ? (
        <Notice
          tone={outcome.failed > 0 ? 'danger' : 'teal'}
          role="status"
          data-testid="codes-run-result"
        >
          <span className="flex flex-col gap-1">
            <span>
              {t(`codes.run.result.done.${pluralForm(locale, outcome.voters)}`, {
                count: outcome.voters,
              })}
            </span>
            {outcome.failed > 0 ? (
              <span>
                {t(`codes.run.result.failed.${pluralForm(locale, outcome.failed)}`, {
                  count: outcome.failed,
                })}
              </span>
            ) : null}
          </span>
        </Notice>
      ) : null}

      <div className="flex flex-col gap-4.5 2xl:flex-row-reverse 2xl:items-start">
        <aside
          aria-label={t('codes.totals.label')}
          className="w-full 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0"
        >
          <Panel
            tone="steps"
            icon="chart"
            title={t('codes.totals.label')}
            id="codes-totals-title"
            testId="codes-totals"
          >
            <dl className="grid grid-cols-3 gap-3 text-center 2xl:grid-cols-1 2xl:text-left">
              <div className="flex flex-col-reverse gap-0.5 rounded-md bg-canvas p-3">
                <dt className="text-sm font-medium text-ink-soft">{t('codes.totals.voters')}</dt>
                <dd
                  data-testid="codes-total-voters"
                  className="font-display text-[28px] leading-9 font-extrabold text-ink"
                >
                  {state.voters}
                </dd>
              </div>
              <div className="flex flex-col-reverse gap-0.5 rounded-md bg-teal-soft p-3">
                <dt className="text-sm font-medium text-teal-ink">{t('codes.totals.withCode')}</dt>
                <dd
                  data-testid="codes-total-with-code"
                  className="font-display text-[28px] leading-9 font-extrabold text-teal-ink"
                >
                  {state.with_code}
                </dd>
              </div>
              <div className="flex flex-col-reverse gap-0.5 rounded-md bg-warm-softer p-3">
                <dt className="text-sm font-medium text-warm-ink">
                  {t('codes.totals.withoutCode')}
                </dt>
                <dd
                  data-testid="codes-total-without-code"
                  className="font-display text-[28px] leading-9 font-extrabold text-warm-ink"
                >
                  {state.without_code}
                </dd>
              </div>
            </dl>
            <div
              role="img"
              aria-label={t('codes.totals.progress', {
                count: state.with_code,
                total: state.voters,
              })}
              className="h-2.5 overflow-hidden rounded-full bg-line-soft"
            >
              <div className="h-full rounded-full bg-primary" style={{ width: `${percent}%` }} />
            </div>
            <p className="text-sm text-ink-soft">{t('codes.note')}</p>
          </Panel>
        </aside>

        <section
          data-testid="codes-zone"
          aria-label={t('codes.zone.label')}
          className="flex min-w-0 flex-1 flex-col gap-3 rounded-2xl border border-line bg-surface p-2.5 shadow-1 md:p-3 2xl:p-5"
        >
          <h3 className="font-display text-lg font-extrabold text-ink">{t('codes.zone.groups')}</h3>
          {state.groups.length === 0 ? (
            <div
              data-testid="codes-empty"
              className="flex flex-col items-center gap-3 rounded-lg border-2 border-dashed border-primary-line bg-surface-alt px-4 py-10 text-center"
            >
              <h4 className="font-display text-lg font-extrabold text-ink">
                {t('codes.empty.title')}
              </h4>
              <p className="max-w-md text-base text-ink-soft">{t('codes.empty.text')}</p>
              <Link
                href={`/admin/elections/${election.id}/voters`}
                className={cx(
                  'ui-control inline-flex min-h-11 items-center rounded-full px-4 text-base font-bold text-primary-hover hover:bg-primary-soft',
                  focusRing,
                )}
              >
                {t('codes.empty.link')}
              </Link>
            </div>
          ) : (
            <ul className="grid grid-cols-1 gap-4 md:grid-cols-2 min-[1500px]:grid-cols-3 min-[2300px]:grid-cols-4">
              {state.groups.map((line: CredentialLine, index) => (
                <li key={lineKey(line)} className="flex min-w-0 [&>section]:w-full">
                  <GroupCard
                    line={line}
                    n={index}
                    files={locked ? [] : downloadsOf(files, line)}
                    zone={election.timezone}
                    running={running}
                    canGenerate={!locked}
                    busy={starting}
                    downloading={downloading}
                    onGenerate={(chosen) => void run(runBodyOf(chosen))}
                    onDownload={(file) => void download(file)}
                  />
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>

      {!locked && lost ? (
        <LostSlipDialog
          election={election.id}
          onLocked={() => void becameLocked()}
          onClose={lostClosed}
        />
      ) : null}
    </div>
  );
}
