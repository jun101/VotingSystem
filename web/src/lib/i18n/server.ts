import { cookies, headers } from 'next/headers';
import { fetchCurrentUser } from '@/lib/api/server';
import { fetchPublicResults } from '@/lib/api/public';
import { fetchVoteInfo } from '@/lib/api/voteInfo';
import { LOCALE_COOKIE, VOTE_LOCALE_COOKIE, isLocale, resolveLocale, type Locale } from './locale';
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

  // A voting page: the language the voter chose with the switch (a cookie of this election's pages
  // only), else the election's own. The site-wide `locale` cookie and the browser do not count here.
  const election = headerStore.get('x-vote-election');

  if (election) {
    const chosen = cookieStore.get(VOTE_LOCALE_COOKIE)?.value;

    if (isLocale(chosen)) {
      locale = chosen;
    } else {
      const info = await fetchVoteInfo(election, headerStore.get('x-forwarded-for'));

      if (info && isLocale(info.language)) locale = info.language;
    }
  }

  // A public results page: the election's own language, the visitor cannot change it. For an
  // election that is not public the visitor's language stays (nothing tells it exists).
  const publicElection = headerStore.get('x-public-election');

  if (publicElection) {
    try {
      const results = await fetchPublicResults(publicElection, headerStore.get('x-forwarded-for'));

      if (results && isLocale(results.language)) locale = results.language;
    } catch {
      // The API did not answer: the page (or its error page) speaks the visitor's language.
    }
  }

  const messages = getMessages(locale);

  return { locale, t: (key, params) => translate(messages, key, params) };
}
