'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useRef, useState, type DragEvent } from 'react';
import { Icon } from '@/components/admin/Icon';
import { focusRing } from '@/components/admin/classes';
import { Panel } from '@/components/elections/Panel';
import { COVER_ICONS, STATUS_ICONS } from '@/components/elections/statusIcon';
import { LiveDot } from '@/components/motion';
import { Notice } from '@/components/ui';
import { cx } from '@/components/ui/cx';
import { uploadVoterFile } from '@/lib/api/browser';
import type { Election, ElectionStatus } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { templateUrl } from '@/lib/api/voterImports';
import { useI18n } from '@/lib/i18n/client';
import { fileLabel, hasVoterFileExtension, importAllowed, uploadErrorKey } from './importRules';
import { REQUIRED_COLUMN, TEMPLATE_COLUMNS, TEMPLATE_ROWS } from './importTemplate';

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

const GLASS_LINK =
  'ui-control inline-flex min-h-11 items-center gap-2 rounded-full border border-glass-line bg-glass pr-4.5 pl-3.5 text-md font-semibold text-surface hover:bg-glass-line max-md:w-full max-md:justify-center ' +
  focusRing;

const DOWNLOAD_PRIMARY =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-primary bg-primary pr-5 pl-4 text-base font-semibold text-surface shadow-button hover:bg-primary-hover max-md:flex-1 ' +
  focusRing;

const DOWNLOAD_OUTLINE =
  'ui-control lift-sm inline-flex min-h-11 items-center justify-center gap-2 rounded-full border border-line-strong bg-surface pr-5 pl-4 text-base font-semibold text-ink shadow-1 hover:bg-primary-soft max-md:flex-1 ' +
  focusRing;

const UPLOAD_PATH = 'M12 16V4M6 10l6-6 6 6M4 20h16';
const DOWNLOAD_PATH = 'M12 4v12M6 10l6 6 6-6M4 20h16';

const STEPS = ['template', 'file', 'check', 'done'] as const;

/** The four steps of the import; `current` is the active one (1 to 4). */
function Stepper({ current }: { current: number }) {
  const { t } = useI18n();

  return (
    <ol
      aria-label={t('voterImport.steps.label')}
      className="m-0 flex list-none flex-wrap items-center gap-2.5 p-0"
    >
      {STEPS.map((step, index) => {
        const n = index + 1;
        const done = n < current;
        const active = n === current;

        return (
          <li key={step} className="contents">
            <span
              data-testid={`import-step-${n}`}
              aria-current={active ? 'step' : undefined}
              className={cx(
                'flex items-center gap-2.5 text-md',
                active ? 'font-bold text-ink' : 'font-medium text-ink-soft',
              )}
            >
              <span
                aria-hidden="true"
                className={cx(
                  'flex size-8 items-center justify-center rounded-full font-display text-base font-extrabold',
                  active && 'bg-primary text-surface shadow-button',
                  done && 'bg-teal-soft text-teal-ink',
                  !active && !done && 'bg-primary-soft text-primary-hover',
                )}
              >
                {done ? <Icon name="check" size={16} /> : n}
              </span>
              <span className={cx(!active && 'max-md:sr-only')}>
                {t(`voterImport.steps.${step}`)}
              </span>
            </span>
            {n < STEPS.length ? (
              <span aria-hidden="true" className="h-0.5 w-10 bg-line max-md:hidden" />
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

/**
 * Screen A09: the template and the file, steps 1 and 2 of the voter import. The template is a
 * plain download of the API; the file is sent as it is and judged by the server (the page
 * refuses nothing). After the upload the page goes to the check, A10. An election that is not a
 * draft or scheduled gets the notice and no drop zone.
 */
export function ImportPage({ election }: { election: Election }) {
  const { t, tIfAny } = useI18n();
  const router = useRouter();
  const input = useRef<HTMLInputElement>(null);

  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const [sending, setSending] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  // A 409 `election_voters_locked`: the election changed since the page was read.
  const [lockedNow, setLockedNow] = useState(false);

  const allowed = importAllowed(election.status) && !lockedNow;
  const votersPage = `/admin/elections/${election.id}/voters`;

  function choose(next: File | undefined) {
    if (!next) return;

    setFile(next);
    setProblem(null);
  }

  function onDrop(event: DragEvent<HTMLButtonElement>) {
    event.preventDefault();
    setDragging(false);

    if (!sending) choose(event.dataTransfer.files[0]);
  }

  async function send() {
    if (!file || sending) return;

    setSending(true);
    setProblem(null);

    try {
      const created = await uploadVoterFile(election.id, file);

      router.push(`${votersPage}/import/${created.id}`);
    } catch (caught) {
      const error = caught instanceof ApiError ? caught : new ApiError(0, 'unknown');
      const key = uploadErrorKey(error);

      if (error.code === 'election_voters_locked') setLockedNow(true);

      setProblem((key ? tIfAny(key) : null) ?? errorText(error, tIfAny));
      setSending(false);
    }
  }

  return (
    <div data-testid="import-page" className="flex flex-col gap-4.5">
      <section
        data-testid="import-hero"
        data-status={election.status}
        aria-labelledby="import-title"
        className="election-hero cover-sweep relative flex min-h-32 flex-wrap items-end gap-x-6 gap-y-4 rounded-xl px-4 py-5 text-surface shadow-2 md:px-7.5 [@media(max-height:820px)]:min-h-24 [@media(max-height:820px)]:py-3.5"
      >
        <span aria-hidden="true" className="absolute -top-6 right-7 -rotate-10 text-glass">
          <Icon name={COVER_ICONS[election.status]} size={210} />
        </span>
        <div className="relative z-10 min-w-0 flex-[1_1_22rem]">
          <span
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
                ? t('voterImport.badgeDraft')
                : t(`elections.status.${election.status}`)}
            </span>
          </span>
          <h2
            id="import-title"
            className="mt-2 mb-0.5 font-display text-[26px] leading-tight font-extrabold text-surface md:text-4xl"
          >
            {t('voterImport.title')}
          </h2>
          <p className="line-clamp-2 text-md break-words text-surface">{t('voterImport.text')}</p>
          <div className="mt-3 flex flex-wrap gap-2">
            <span className={CHIP}>
              <Icon name="user" size={18} />
              {t('voterImport.chips.rows')}
            </span>
            <span className={CHIP}>
              <Icon name="blank" size={18} />
              {t('voterImport.chips.formats')}
            </span>
            <span className={CHIP}>
              <Icon name="lock" size={18} />
              {t('voterImport.chips.nothing')}
            </span>
          </div>
        </div>
        <div className="relative z-10 flex w-full flex-wrap gap-2.5 md:ml-auto md:w-auto">
          <Link href={votersPage} data-testid="import-back" className={GLASS_LINK}>
            <Icon name="left" size={18} />
            {t('voterImport.back')}
          </Link>
        </div>
      </section>

      <Stepper current={1} />

      {!allowed ? (
        <Notice tone="info" data-testid="import-locked-notice">
          {t('voterImport.locked')}
        </Notice>
      ) : null}

      <div className="flex flex-col gap-4.5 2xl:flex-row 2xl:items-start">
        <div className="flex min-w-0 flex-1 flex-col gap-4.5">
          <section
            aria-label={t('voterImport.template.zone')}
            className="flex min-w-0 flex-col gap-4 rounded-2xl border border-line bg-surface p-3 shadow-1 md:p-5"
          >
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
              <h3 className="flex items-center gap-2.5 font-display text-xl font-extrabold text-ink">
                <span
                  aria-hidden="true"
                  className="flex size-7.5 items-center justify-center rounded-full bg-primary font-display text-base text-surface"
                >
                  {1}
                </span>
                {t('voterImport.template.title')}
              </h3>
              <div className="flex w-full gap-2 md:ml-auto md:w-auto">
                <a
                  href={templateUrl(election.id, 'xlsx')}
                  download="modele-electeurs.xlsx"
                  data-testid="import-template-xlsx"
                  className={DOWNLOAD_PRIMARY}
                >
                  <Icon path={DOWNLOAD_PATH} size={18} />
                  {t('voterImport.template.xlsx')}
                </a>
                <a
                  href={templateUrl(election.id, 'csv')}
                  download="modele-electeurs.csv"
                  data-testid="import-template-csv"
                  className={DOWNLOAD_OUTLINE}
                >
                  <Icon name="blank" size={18} />
                  {t('voterImport.template.csv')}
                </a>
              </div>
              <p className="basis-full text-base text-ink-soft">{t('voterImport.template.text')}</p>
            </div>

            <div
              data-testid="import-template-preview"
              role="region"
              aria-label={t('voterImport.template.previewLabel')}
              tabIndex={0}
              className={cx('overflow-x-auto rounded-lg border border-line', focusRing)}
            >
              <table className="w-full min-w-max border-collapse text-base">
                <thead>
                  <tr className="bg-primary-soft text-left text-primary-hover">
                    <td className="w-10 px-3 py-2" />
                    {TEMPLATE_COLUMNS.map((column) => (
                      <th
                        key={column}
                        scope="col"
                        className="px-3 py-2 font-bold whitespace-nowrap"
                      >
                        {column}
                        {column === REQUIRED_COLUMN ? (
                          <span className="sr-only">{` (${t('voterImport.template.required')})`}</span>
                        ) : null}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {TEMPLATE_ROWS.map((row, index) => (
                    <tr key={index} className="border-t border-line-soft">
                      <th scope="row" className="bg-surface-alt px-3 py-2 text-ink-soft">
                        {index + 2}
                      </th>
                      {row.map((cell, at) => (
                        <td key={at} className="px-3 py-2 whitespace-nowrap text-ink">
                          {cell}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div data-testid="import-instructions" className="flex flex-col gap-3">
              <h4 className="font-display text-lg font-extrabold text-ink">
                {t('voterImport.template.instructionsTitle')}
              </h4>
              <dl className="grid grid-cols-1 gap-3 md:grid-cols-2">
                {TEMPLATE_COLUMNS.map((column) => (
                  <div key={column} className="rounded-lg bg-surface-alt p-3">
                    <dt className="flex flex-wrap items-center gap-2 text-base font-bold text-ink">
                      <code className="rounded-sm bg-primary-soft px-1.5 text-primary-hover">
                        {column}
                      </code>
                      {t(`voterImport.template.columns.${column}.label`)}
                      {column === REQUIRED_COLUMN ? (
                        <span className="text-danger" aria-hidden="true">
                          {'*'}
                        </span>
                      ) : null}
                    </dt>
                    <dd className="mt-1 text-base text-ink-soft">
                      {t(`voterImport.template.columns.${column}.help`)}
                    </dd>
                  </div>
                ))}
              </dl>
              <Notice tone="info">{t('voterImport.template.english')}</Notice>
            </div>
          </section>

          {allowed ? (
            <section
              aria-label={t('voterImport.file.zone')}
              className="flex min-w-0 flex-col gap-4 rounded-2xl border border-line bg-surface p-3 shadow-1 md:p-5"
            >
              <div className="flex flex-col gap-1">
                <h3 className="flex items-center gap-2.5 font-display text-xl font-extrabold text-ink">
                  <span
                    aria-hidden="true"
                    className="flex size-7.5 items-center justify-center rounded-full bg-primary font-display text-base text-surface"
                  >
                    {2}
                  </span>
                  {t('voterImport.file.title')}
                </h3>
                <p className="text-base text-ink-soft">{t('voterImport.file.text')}</p>
              </div>

              <input
                ref={input}
                type="file"
                data-testid="import-file-input"
                accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                tabIndex={-1}
                aria-hidden="true"
                className="hidden"
                onChange={(event) => {
                  choose(event.target.files?.[0]);
                  // The same file chosen again must still fire a change.
                  event.target.value = '';
                }}
              />

              <button
                type="button"
                data-testid="import-dropzone"
                disabled={sending}
                onClick={() => input.current?.click()}
                onDragOver={(event) => {
                  event.preventDefault();
                  setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={cx(
                  'ui-control flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center hover:bg-primary-soft disabled:opacity-60',
                  dragging
                    ? 'border-primary bg-primary-soft'
                    : 'border-primary-line bg-surface-alt',
                  focusRing,
                )}
              >
                <span
                  aria-hidden="true"
                  className="flex size-16 items-center justify-center rounded-full bg-primary text-surface shadow-button"
                >
                  <Icon path={UPLOAD_PATH} size={30} />
                </span>
                <b className="font-display text-lg font-extrabold text-primary-hover">
                  {t('voterImport.file.drop')}
                </b>
                <small className="text-base text-ink-soft">{t('voterImport.file.limits')}</small>
              </button>

              {file ? (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-line bg-surface-alt p-3">
                  <span aria-hidden="true" className="text-primary">
                    <Icon name="blank" size={22} />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm text-ink-soft">{t('voterImport.file.chosen')}</p>
                    <p
                      data-testid="import-file-name"
                      className="font-semibold break-words text-ink"
                    >
                      {fileLabel(file.name)}
                    </p>
                    {!hasVoterFileExtension(file.name) ? (
                      <p className="text-sm text-ink-soft">{t('voterImport.file.hint')}</p>
                    ) : null}
                  </div>
                </div>
              ) : null}

              {problem ? (
                <Notice tone="danger" role="alert" data-testid="import-error">
                  {problem}
                </Notice>
              ) : null}

              <div className="flex">
                <button
                  type="button"
                  data-testid="import-upload"
                  disabled={!file || sending}
                  onClick={() => void send()}
                  className={cx(
                    'ui-control lift-sm inline-flex min-h-12 items-center justify-center gap-2 rounded-full border border-accent bg-accent pr-5.5 pl-4 text-md font-bold text-deep shadow-button disabled:opacity-50 max-md:w-full',
                    focusRing,
                  )}
                >
                  <Icon path={UPLOAD_PATH} size={20} />
                  {sending ? t('voterImport.file.uploading') : t('voterImport.file.upload')}
                </button>
              </div>
            </section>
          ) : problem ? (
            <Notice tone="danger" role="alert" data-testid="import-error">
              {problem}
            </Notice>
          ) : null}
        </div>

        <aside
          aria-label={t('voterImport.rail.label')}
          className="flex w-full flex-wrap gap-4.5 2xl:sticky 2xl:top-20 2xl:w-[340px] 2xl:shrink-0 2xl:flex-col"
        >
          <Panel
            tone="steps"
            icon="check"
            title={t('voterImport.rail.title')}
            id="import-know-title"
            testId="import-know"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <ul className="m-0 flex list-none flex-col gap-3 p-0">
              {(
                [
                  ['nothing', 'check'],
                  ['errors', 'warn'],
                  ['duplicates', 'user'],
                  ['groups', 'list'],
                ] as const
              ).map(([key, icon]) => (
                <li key={key} className="flex items-start gap-3">
                  <span
                    aria-hidden="true"
                    className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-hover"
                  >
                    <Icon name={icon} size={16} />
                  </span>
                  <span className="text-base text-ink-soft">
                    <b className="block text-ink">{t(`voterImport.rail.${key}.title`)}</b>
                    {t(`voterImport.rail.${key}.text`)}
                  </span>
                </li>
              ))}
            </ul>
          </Panel>
          <Panel
            tone="info"
            icon="lock"
            title={t('voterImport.rail.lockedTitle')}
            id="import-lock-title"
            className="flex-[1_1_20rem] 2xl:flex-none"
          >
            <p className="text-base text-ink-soft">{t('voterImport.rail.lockedText')}</p>
          </Panel>
        </aside>
      </div>
    </div>
  );
}
