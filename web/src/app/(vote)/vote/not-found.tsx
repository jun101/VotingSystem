import { headers } from 'next/headers';
import { Unavailable } from '@/components/vote/Unavailable';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

/**
 * Any /vote/<address> that shows no election: a draft, an unknown one, a malformed address. The
 * answer is the same for all (HTTP 404, same words), so it does not tell a draft from nothing.
 * The language switch is offered where the server can read its cookie (an address shaped as an
 * election's).
 */
export default async function VoteNotAvailable() {
  const { locale, t } = await getI18n();
  const toggle = ((await headers()).get('x-vote-election') ?? '') !== '';

  return (
    <I18nProvider locale={locale} messages={pick(getMessages(locale), 'vote', 'errors')}>
      <Unavailable productName={t('app.name')} toggle={toggle} />
    </I18nProvider>
  );
}
