import { cookies, headers } from 'next/headers';
import { LOCALE_COOKIE, resolveLocale, type Locale } from './locale';
import { getMessages, translate, type Translate } from './messages';

/** The visitor's language and its translator, for server components. */
export async function getI18n(): Promise<{ locale: Locale; t: Translate }> {
  const [cookieStore, headerStore] = await Promise.all([cookies(), headers()]);

  const locale = resolveLocale({
    cookie: cookieStore.get(LOCALE_COOKIE)?.value,
    acceptLanguage: headerStore.get('accept-language'),
  });
  const messages = getMessages(locale);

  return { locale, t: (key, params) => translate(messages, key, params) };
}
