import type { Locale } from '@/lib/i18n/locale';

/** A date and time in the visitor's language. The API sends UTC; so does the text. */
export function formatDateTime(iso: string, locale: Locale): string {
  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) return iso;

  return new Intl.DateTimeFormat(locale, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: 'UTC',
    timeZoneName: 'short',
  }).format(date);
}
