/** Languages of the interface (NFR-UX-01). French is the default. */
export const LOCALES = ['fr', 'en'] as const;
export type Locale = (typeof LOCALES)[number];

export const DEFAULT_LOCALE: Locale = 'fr';
export const LOCALE_COOKIE = 'locale';
/** The language a voter chose with the FR/EN switch: a session cookie scoped to one election's pages. */
export const VOTE_LOCALE_COOKIE = 'vote_locale';

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

/** Remembers the language the visitor chose on a public page (a year); the server reads it on the next render. */
export function rememberLocale(locale: Locale): void {
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=31536000; samesite=lax`;
}

/** The path a voting page lives under: `/vote/<election>`, as the browser has it (the cookie path is case sensitive). */
function votePath(): string | null {
  const [, section, election] = window.location.pathname.split('/');

  return section === 'vote' && election ? `/vote/${election}` : null;
}

/**
 * Remembers the language a voter chose on a voting page, until the browser is closed or the voting
 * session ends: no max-age, and a path of this election's pages only, so no other page, election or
 * voter ever sees it. The server reads it on the next render.
 */
export function rememberVoteLocale(locale: Locale): void {
  const path = votePath();

  if (path === null) return;

  document.cookie = `${VOTE_LOCALE_COOKIE}=${locale}; path=${path}; samesite=strict`;
}

/** Forgets the voter's choice (next voter, sign out, end of the screen). True when there was one. */
export function forgetVoteLocale(): boolean {
  const path = votePath();

  if (path === null) return false;

  const had = document.cookie.split('; ').some((part) => part.startsWith(`${VOTE_LOCALE_COOKIE}=`));

  if (had) {
    document.cookie = `${VOTE_LOCALE_COOKIE}=; path=${path}; expires=Thu, 01 Jan 1970 00:00:00 GMT; samesite=strict`;
  }

  return had;
}
