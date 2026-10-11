import type { IconName } from '@/components/admin/Icon';
import type { AuditFilters } from '@/lib/api/audit';
import type { Locale } from '@/lib/i18n/locale';

/** The families of actions, in the order of the select: the prefix is the code up to its dot. */
export const AUDIT_FAMILIES = [
  'election',
  'voter',
  'voters',
  'credentials',
  'second_round',
  'auth',
] as const;

export type AuditFamily = (typeof AUDIT_FAMILIES)[number];

/** The icon of each family (decoration; the action is always written in words). */
const FAMILY_ICONS: Record<AuditFamily, IconName> = {
  election: 'ballot',
  voter: 'user',
  voters: 'people',
  credentials: 'qr',
  second_round: 'shuffle',
  auth: 'lock',
};

/** The tone classes of the icon disc, by family (tokens only). */
const FAMILY_TONES: Record<AuditFamily, string> = {
  election: 'bg-primary-soft text-primary-hover',
  voter: 'bg-teal-soft text-teal-ink',
  voters: 'bg-teal-soft text-teal-ink',
  credentials: 'bg-warm-soft text-warm-ink',
  second_round: 'bg-status-published-soft text-status-published',
  auth: 'bg-canvas text-ink-soft',
};

/** The family of an action code (`election.closed` is `election`), or null for one not known. */
export function familyOf(code: string): AuditFamily | null {
  const prefix = code.split('.')[0] ?? '';

  return (AUDIT_FAMILIES as readonly string[]).includes(prefix) ? (prefix as AuditFamily) : null;
}

export function iconOf(code: string): IconName {
  const family = familyOf(code);

  return family ? FAMILY_ICONS[family] : 'log';
}

export function toneOf(code: string): string {
  const family = familyOf(code);

  return family ? FAMILY_TONES[family] : 'bg-canvas text-ink-soft';
}

/** The message key that says an action in words. */
export function actionKey(code: string): string {
  return `audit.actions.${code}`;
}

/** Whether any filter narrows the log. */
export function isFiltering(filters: AuditFilters): boolean {
  return Object.values(filters).some((value) => value !== '');
}

/** More entries to read than the ones on the screen. */
export function hasMore(shown: number, total: number): boolean {
  return shown < total;
}

/**
 * The day and the time of an instant, in the browser's zone or, with a zone, in that one
 * (`UTC` for the tooltip, the tests).
 */
export function whenOf(
  iso: string,
  locale: Locale,
  zone?: string,
): { day: string; time: string; instant: string } | null {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return null;

  const base = zone ? { timeZone: zone } : {};

  return {
    day: new Intl.DateTimeFormat(locale, {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      ...base,
    }).format(date),
    time: new Intl.DateTimeFormat(locale, {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      ...base,
    }).format(date),
    instant: `${date.toISOString().slice(0, 10)} ${date.toISOString().slice(11, 19)}`,
  };
}
