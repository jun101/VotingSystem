import { cookies, headers } from 'next/headers';
import { fetchCurrentUser } from '@/lib/api/server';
import { fetchVoteInfo } from '@/lib/api/voteInfo';
import { LOCALE_COOKIE, isLocale, resolveLocale, type Locale } from './locale';
import { getMessages, translate, type Translate } from './messages';

/**
 * The visitor's language and its translator, for server components. In the admin area
 * (the proxy marks the request) it is the signed-in user's stored language; everywhere else
 * it follows the browser. On a voting page it is the election's, until the voter picks one.
 */
export async function getI18n(): Promise<{ locale: Locale; t: Translate }> {
  const [cookieStore, headerStore] = await Promise.all([cookies(), headers()]);

  let locale = resolveLocale({
    cookie: cookieStore.get(LOCALE_COOKIE)?.value,
    acceptLanguage: headerStore.get('accept-language'),
  });

  if (headerStore.get('x-admin-area') === '1') {
    const user = await fetchCurrentUser();

    if (user && isLocale(user.language)) locale = user.language;
  }

  // A voting page: the election's language, unless the voter chose one with the switch (cookie).
  const election = headerStore.get('x-vote-election');

  if (election && !isLocale(cookieStore.get(LOCALE_COOKIE)?.value)) {
    const info = await fetchVoteInfo(election, headerStore.get('x-forwarded-for'));

    if (info && isLocale(info.language)) locale = info.language;
  }

  const messages = getMessages(locale);

  return { locale, t: (key, params) => translate(messages, key, params) };
}
