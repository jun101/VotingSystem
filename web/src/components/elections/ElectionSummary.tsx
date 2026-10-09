'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { linkPrimary } from '@/components/admin/classes';
import { Button, Notice } from '@/components/ui';
import { duplicateElection } from '@/lib/api/browser';
import type { Election } from '@/lib/api/elections';
import { ApiError, errorText } from '@/lib/api/errors';
import { useI18n } from '@/lib/i18n/client';
import { DeleteElectionDialog } from './DeleteElectionDialog';
import { figuresText } from './figuresText';
import { NextSteps } from './NextSteps';
import { momentText } from './scheduleText';
import { StatusPill } from './StatusPill';

/**
 * The summary page of one election: the facts on the left, the cover and the next steps on the
 * right from `lg`. The actions are here: edit, duplicate and delete for a draft; only duplicate
 * for any other status.
 */
export function ElectionSummary({ election }: { election: Election }) {
  const { t, tIfAny, locale } = useI18n();
  const router = useRouter();
  const draft = election.status === 'draft';
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const start = new Date(election.starts_at);
  const end = new Date(election.ends_at);
  const dates = t('elections.schedule.line', {
    start: momentText(start, locale, election.timezone, t),
    end: momentText(end, locale, election.timezone, t),
  });

  async function duplicate() {
    setProblem(null);
    setBusy(true);

    try {
      const copy = await duplicateElection(election.id);

      router.push(`/admin/elections/${copy.id}`);
    } catch (caught) {
      setProblem(
        errorText(caught instanceof ApiError ? caught : new ApiError(0, 'unknown'), tIfAny),
      );
      setBusy(false);
    }
  }

  const facts: { label: string; value: string; testId?: string }[] = [
    { label: t('elections.summary.dates'), value: dates, testId: 'election-summary-dates' },
    {
      label: t('elections.summary.timezone'),
      value: election.timezone,
      testId: 'election-summary-timezone',
    },
    {
      label: t('elections.summary.language'),
      value: t(`elections.form.languages.${election.language}`),
    },
    {
      label: t('elections.summary.order'),
      value: t(`elections.form.order.${election.candidate_order}`),
    },
    {
      label: t('elections.summary.results'),
      value: t(`elections.form.results.${election.results_display}`),
    },
    { label: t('elections.summary.ballots'), value: figuresText(election, locale, t) },
  ];

  return (
    <div
      data-testid="election-page"
      className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start lg:gap-6"
    >
      <section
        aria-labelledby="election-summary-title"
        className="flex min-w-0 flex-col gap-4 rounded-lg border border-line bg-surface p-4 sm:p-5"
      >
        <div className="flex flex-col items-start gap-2">
          <StatusPill status={election.status} data-testid="election-summary-status" />
          <h2
            id="election-summary-title"
            data-testid="election-summary-title"
            className="font-display text-2xl font-bold break-words text-ink"
          >
            {election.title}
          </h2>
          {election.description ? (
            <p className="text-md break-words whitespace-pre-line text-ink-soft">
              {election.description}
            </p>
          ) : null}
        </div>

        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          {facts.map((fact, index) => (
            <div key={fact.label} className={index === 0 ? 'sm:col-span-2' : undefined}>
              <dt className="text-sm font-semibold text-ink-soft">{fact.label}</dt>
              <dd data-testid={fact.testId} className="text-md text-ink">
                {fact.value}
              </dd>
            </div>
          ))}
        </dl>

        {draft ? null : <Notice tone="info">{t('elections.summary.locked')}</Notice>}

        {problem ? (
          <Notice tone="danger" role="alert" data-testid="election-summary-error">
            {problem}
          </Notice>
        ) : null}

        <div
          role="group"
          aria-label={t('elections.summary.actions')}
          className="flex flex-wrap gap-2"
        >
          {draft ? (
            <Link
              href={`/admin/elections/${election.id}/edit`}
              data-testid="election-edit"
              className={linkPrimary}
            >
              {t('elections.summary.edit')}
            </Link>
          ) : null}
          <Button
            variant="secondary"
            loading={busy}
            onClick={duplicate}
            data-testid="election-duplicate"
          >
            {t('elections.summary.duplicate')}
          </Button>
          {draft ? (
            <Button
              variant="danger"
              disabled={busy}
              onClick={() => setAsking(true)}
              data-testid="election-delete"
            >
              {t('elections.summary.delete')}
            </Button>
          ) : null}
        </div>
      </section>

      <div className="flex min-w-0 flex-col gap-4">
        {election.cover ? (
          <section
            aria-labelledby="election-summary-cover-title"
            className="overflow-hidden rounded-lg border border-line bg-surface"
          >
            <h2 id="election-summary-cover-title" className="sr-only">
              {t('elections.summary.coverTitle')}
            </h2>
            {/* The 960 px file: a plain image with its ratio set. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img
              src={election.cover.md}
              alt={t('elections.cover.summaryAlt')}
              width={960}
              height={480}
              data-testid="election-summary-cover"
              className="aspect-2/1 w-full bg-canvas object-cover"
            />
          </section>
        ) : null}
        <NextSteps soon title={t('elections.summary.nextTitle')} />
      </div>

      {asking ? (
        <DeleteElectionDialog
          election={election}
          onCancel={() => setAsking(false)}
          onDeleted={() => router.push('/admin/elections')}
        />
      ) : null}
    </div>
  );
}
