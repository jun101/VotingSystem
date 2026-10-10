'use client';

import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { Checklist } from '@/lib/api/elections';
import { pluralForm } from '@/lib/format/electionDates';
import { useI18n } from '@/lib/i18n/client';
import { itemText } from './checklist';
import { Panel, PANEL_ASIDE } from './Panel';

/**
 * The checklist of a draft (A05): what blocks the scheduling, then what to look at, in words. A
 * list with neither says everything is ready. `checklist` is null while it loads and `failed`
 * when it could not be read.
 */
export function ChecklistCard({
  checklist,
  failed,
  className,
}: {
  checklist: Checklist | null;
  failed: boolean;
  className?: string;
}) {
  const { t, locale } = useI18n();
  const blockers = checklist?.blockers ?? [];
  const warnings = checklist?.warnings ?? [];
  const nothing = checklist !== null && blockers.length === 0 && warnings.length === 0;

  return (
    <Panel
      tone="steps"
      icon="check"
      title={t('elections.checklist.title')}
      id="election-checklist-title"
      testId="election-checklist"
      headerTestId="election-card-header-checklist"
      className={className}
      aside={
        checklist && !nothing ? (
          <span className={PANEL_ASIDE}>
            {blockers.length > 0
              ? t(`elections.checklist.blockersCount.${pluralForm(locale, blockers.length)}`, {
                  count: blockers.length,
                })
              : t(`elections.checklist.warningsCount.${pluralForm(locale, warnings.length)}`, {
                  count: warnings.length,
                })}
          </span>
        ) : null
      }
    >
      {failed ? (
        <p role="alert" className="text-base font-medium text-danger">
          {t('elections.checklist.failed')}
        </p>
      ) : checklist === null ? (
        <p role="status" className="text-base text-ink-soft">
          {t('elections.checklist.loading')}
        </p>
      ) : null}

      {nothing ? (
        <p
          data-testid="checklist-ok"
          className="flex items-center gap-2.5 rounded-md bg-teal-soft px-3.5 py-3 text-base font-medium text-teal-ink"
        >
          <Icon name="check" size={20} />
          {t('elections.checklist.ok')}
        </p>
      ) : null}

      {blockers.length > 0 ? (
        <div className="flex flex-col gap-2">
          <h3 className="text-sm font-bold tracking-wider text-danger uppercase">
            {t('elections.checklist.blockers')}
          </h3>
          <ul data-testid="checklist-blockers" className="flex flex-col gap-2">
            {blockers.map((item, index) => (
              <li
                key={`${item.code}-${'ballot' in item && item.ballot ? item.ballot.id : index}`}
                data-testid={`checklist-blocker-${index + 1}`}
                className={cx(
                  'flex items-start gap-2.5 rounded-md border border-danger-line bg-warm-softer px-3.5 py-2.5 text-base text-ink',
                )}
              >
                <span className="mt-0.5 shrink-0 text-danger">
                  <Icon name="close" size={18} />
                </span>
                <span className="min-w-0 break-words">{itemText(item, locale, t)}</span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      {warnings.length > 0 ? (
        <div className="flex flex-col gap-2">
          <h3 className="text-sm font-bold tracking-wider text-warm-ink uppercase">
            {t('elections.checklist.warnings')}
          </h3>
          <ul data-testid="checklist-warnings" className="flex flex-col gap-2">
            {warnings.map((item, index) => (
              <li
                key={`${item.code}-${'ballot' in item && item.ballot ? item.ballot.id : index}`}
                data-testid={`checklist-warning-${index + 1}`}
                className="flex items-start gap-2.5 rounded-md border border-warm bg-warm-softer px-3.5 py-2.5 text-base text-ink"
              >
                <span className="mt-0.5 shrink-0 text-warm-ink">
                  <Icon name="warn" size={18} />
                </span>
                <span className="min-w-0 break-words">{itemText(item, locale, t)}</span>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
    </Panel>
  );
}
