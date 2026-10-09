'use client';

import { Pill } from '@/components/ui';
import { useI18n } from '@/lib/i18n/client';

const STEPS = ['ballots', 'voters', 'schedule', 'codes'] as const;

/**
 * The four steps after the draft (ballots, voters, scheduling, codes). On the form they are a
 * reminder; on the summary page, where `soon` is set, each says it is not available yet (they
 * open in the next slices).
 */
export function NextSteps({ soon = false, title }: { soon?: boolean; title: string }) {
  const { t } = useI18n();

  return (
    <section
      data-testid="election-next-steps"
      aria-labelledby="election-next-steps-title"
      className="rounded-lg border border-line bg-surface p-4"
    >
      <h2 id="election-next-steps-title" className="mb-3 text-lg font-bold text-ink">
        {title}
      </h2>
      <ol className="flex flex-col gap-2.5">
        {STEPS.map((step, index) => (
          <li key={step} className="flex items-start gap-3">
            <span
              aria-hidden="true"
              className="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-primary"
            >
              {index + 1}
            </span>
            <span className="min-w-0 flex-1 text-base text-ink-soft">
              <strong className="font-semibold text-ink">
                {t(`elections.next.${step}.title`)}
              </strong>
              {t('elections.next.separator')}
              {t(`elections.next.${step}.text`)}
              {soon ? (
                <Pill tone="neutral" className="ml-2 align-middle">
                  {t('elections.summary.soon')}
                </Pill>
              ) : null}
            </span>
          </li>
        ))}
      </ol>
      {soon ? null : <p className="mt-3 text-sm text-ink-soft">{t('elections.next.footer')}</p>}
    </section>
  );
}
