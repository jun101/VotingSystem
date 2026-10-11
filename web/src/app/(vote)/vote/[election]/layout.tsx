import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { VoteProvider } from '@/components/vote/VoteProvider';
import { fetchVoteInfo } from '@/lib/api/voteInfo';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

// Read at each visit: the phase of an election changes with the clock.
export const dynamic = 'force-dynamic';

type Props = { children: ReactNode; params: Promise<{ election: string }> };

export async function generateMetadata({ params }: Pick<Props, 'params'>): Promise<Metadata> {
  const { election } = await params;
  const info = await fetchVoteInfo(election, (await headers()).get('x-forwarded-for'));

  return {
    title: info?.title,
    // The personal link carries the code after "#": no page we link to may learn the address.
    referrer: 'no-referrer',
    robots: { index: false, follow: false },
  };
}

/**
 * The voter's pages (docs/api/vote/): public information from the server, the rest in the browser
 * with the voter's session cookie. An election that is a draft, or unknown, is a 404.
 */
export default async function VoteLayout({ children, params }: Props) {
  const { election } = await params;
  const info = await fetchVoteInfo(election, (await headers()).get('x-forwarded-for'));

  if (!info) notFound();

  const { locale } = await getI18n();

  return (
    <I18nProvider locale={locale} messages={pick(getMessages(locale), 'vote', 'errors')}>
      <VoteProvider election={info.id} initial={info}>
        {children}
      </VoteProvider>
    </I18nProvider>
  );
}
