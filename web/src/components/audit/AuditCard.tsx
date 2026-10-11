'use client';

import { Icon } from '@/components/admin/Icon';
import { cx } from '@/components/ui/cx';
import type { AuditEntry } from '@/lib/api/audit';
import { useI18n } from '@/lib/i18n/client';
import { actionKey, iconOf, toneOf, whenOf } from './auditModel';

/**
 * One entry of the log as a card: an icon by family, the action in words (the raw code when no
 * words exist for it), who did it, the election, when and from which address. `n` in the test ids
 * is the 1-based place on the page, newest first. `local` writes the time in the browser's zone.
 */
export function AuditCard({ entry, n, local }: { entry: AuditEntry; n: number; local: boolean }) {
  const { t, tIfAny, locale } = useI18n();
  const words = tIfAny(actionKey(entry.action));
  const actor = entry.actor.id === null ? t('audit.system') : entry.actor.name;
  const shown = whenOf(entry.created_at, locale, local ? undefined : 'UTC');
  const utc = whenOf(entry.created_at, locale, 'UTC');

  return (
    <li
      data-testid={`audit-entry-${n}`}
      className="lift flex min-w-0 items-start gap-3 rounded-lg border border-line bg-surface p-3.5 shadow-1"
    >
      <span
        aria-hidden="true"
        className={cx(
          'flex size-11 shrink-0 items-center justify-center rounded-full',
          toneOf(entry.action),
        )}
      >
        <Icon name={iconOf(entry.action)} size={22} />
      </span>
      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
        <b
          data-testid={`audit-action-${n}`}
          data-action={entry.action}
          className="text-md leading-tight font-semibold break-words text-ink"
        >
          {words ?? entry.action}
        </b>
        <span data-testid={`audit-actor-${n}`} className="text-sm break-words text-ink-soft">
          {t('audit.by', { name: actor })}
        </span>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
          {entry.election ? (
            <span
              data-testid={`audit-election-${n}`}
              title={entry.election.title}
              className="inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-full bg-primary-soft px-2.5 py-1 font-medium text-primary-hover"
            >
              <Icon name="ballot" size={14} />
              <span className="truncate">{entry.election.title}</span>
            </span>
          ) : null}
          {shown ? (
            <time
              data-testid={`audit-time-${n}`}
              dateTime={entry.created_at}
              title={utc ? t('audit.utc', { instant: utc.instant }) : undefined}
              className="inline-flex items-center gap-1.5 text-ink-soft"
            >
              <Icon name="clock" size={14} />
              {t('audit.time', { day: shown.day, time: shown.time })}
            </time>
          ) : null}
          {entry.ip_address ? (
            <span data-testid={`audit-ip-${n}`} className="font-mono break-all text-ink-soft">
              {entry.ip_address}
            </span>
          ) : null}
        </div>
      </div>
    </li>
  );
}
