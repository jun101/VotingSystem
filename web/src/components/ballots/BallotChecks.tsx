'use client';

import { focusRing } from '@/components/admin/classes';
import { Icon } from '@/components/admin/Icon';
import { PANEL_ASIDE, Panel } from '@/components/elections/Panel';
import { cx } from '@/components/ui/cx';
import type { Ballot } from '@/lib/api/ballots';
import { useI18n } from '@/lib/i18n/client';
import type { Check } from './candidateList';

/**
 * The "to check" card of the rail: every ballot with no candidate or just one, with a link that
 * opens the candidate modal for it (a draft only). Nothing to list says so in one line.
 */
export function BallotChecks({
  checks,
  editable,
  onAdd,
}: {
  checks: Check[];
  editable: boolean;
  onAdd: (ballot: Ballot) => void;
}) {
  const { t } = useI18n();

  return (
    <Panel
      tone="steps"
      icon="warn"
      title={t('ballots.checks.title')}
      id="ballots-checks-title"
      testId="ballots-checks"
      className="flex-[1_1_20rem] 2xl:flex-none"
      bodyClassName="gap-2"
      aside={<span className={PANEL_ASIDE}>{checks.length}</span>}
    >
      {checks.length === 0 ? (
        <p data-testid="ballots-checks-empty" className="text-base text-ink-soft">
          {t('ballots.checks.empty')}
        </p>
      ) : (
        <ul aria-label={t('ballots.checks.label')} className="flex flex-col gap-2">
          {checks.map((check) => (
            <li
              key={check.ballot.id}
              data-testid={`ballots-check-${check.n}`}
              className="flex min-w-0 items-start gap-3 rounded-lg bg-warm-softer p-2.5"
            >
              <span
                aria-hidden="true"
                className="flex size-9.5 shrink-0 items-center justify-center rounded-full bg-warm-soft text-warm"
              >
                <Icon name={check.kind === 'none' ? 'user' : 'warn'} size={20} />
              </span>
              <span className="flex min-w-0 flex-col items-start gap-0.5">
                <b className="text-md leading-tight font-medium break-words text-warm-ink">
                  {t(check.kind === 'none' ? 'ballots.checks.none' : 'ballots.checks.one', {
                    title: check.ballot.title,
                  })}
                </b>
                {editable ? (
                  <button
                    type="button"
                    aria-label={t('ballots.checks.addTo', { title: check.ballot.title })}
                    data-testid={`ballots-check-add-${check.n}`}
                    onClick={() => onAdd(check.ballot)}
                    className={cx(
                      'inline-flex min-h-11 items-center gap-1 rounded-full text-sm font-bold text-danger hover:underline md:min-h-8',
                      focusRing,
                    )}
                  >
                    {t('ballots.checks.add')}
                    <Icon name="right" size={14} />
                  </button>
                ) : null}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}
