/** Languages of the interface (NFR-UX-01). French is the default. */
export const LOCALES = ['fr', 'en'] as const;
export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = 'fr';
export const LOCALE_COOKIE = 'locale';

export function isLocale(value: string | null | undefined): value is Locale {
  return LOCALES.some((locale) => locale === value);
}

/**
 * The language of a visitor: the `locale` cookie if it holds `fr` or `en`, else the best
 * match in the browser's `Accept-Language`, else French.
 */
export function resolveLocale(input: {
  cookie?: string | null;
  acceptLanguage?: string | null;
}): Locale {
  if (isLocale(input.cookie)) return input.cookie;

  const ranked = (input.acceptLanguage ?? '')
    .split(',')
    .map((part, position) => {
      const [tag = '', ...parameters] = part.trim().split(';');
      const quality = parameters.map((p) => p.trim()).find((p) => p.startsWith('q='));
      const weight = quality ? Number.parseFloat(quality.slice(2)) : 1;

      return { language: tag.trim().toLowerCase().split('-')[0], weight, position };
    })
    .filter((entry) => entry.weight > 0 && Number.isFinite(entry.weight))
    .sort((a, b) => b.weight - a.weight || a.position - b.position);

  const match = ranked.find((entry) => isLocale(entry.language));

  return isLocale(match?.language) ? match.language : DEFAULT_LOCALE;
}
