import type { Metadata } from 'next';
import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import { PublicResultsView } from '@/components/public/PublicResultsView';
import { fetchPublicResults } from '@/lib/api/public';
import { isLocale } from '@/lib/i18n/locale';
import { getMessages, translate } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';
import { absoluteUrl, electedNames, originOf, shareLinks } from '@/lib/public/share';
import { percentText } from '@/components/turnout/turnoutModel';

// Read at each visit: an unpublish takes the page down at once.
export const dynamic = 'force-dynamic';

type Props = { params: Promise<{ election: string }> };

const OPEN_GRAPH_LOCALE = { fr: 'fr_FR', en: 'en_US' } as const;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { election } = await params;
  const forwardedFor = (await headers()).get('x-forwarded-for');
  let results: Awaited<ReturnType<typeof fetchPublicResults>> = null;

  try {
    results = await fetchPublicResults(election, forwardedFor);
  } catch {
    // The page itself raises the error; the metadata only stays out of the way.
  }

  if (!results) return { robots: { index: false, follow: false } };

  const locale = isLocale(results.language) ? results.language : 'fr';
  const messages = getMessages(locale);
  const origin = originOf(await headers(), process.env.SITE_URL);
  const url = absoluteUrl(origin, `/results/${results.id}`);
  const image = absoluteUrl(origin, `/results/${results.id}/opengraph-image`);
  const title = translate(messages, 'public.meta.title', {
    title: results.title,
    institution: results.institution.name,
  });
  const names = electedNames(results.ballots);
  const percent = percentText(results.turnout.percentage, locale);
  const list = new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' });
  const description =
    names.length > 0
      ? translate(
          messages,
          names.length === 1 ? 'public.meta.elected.one' : 'public.meta.elected.other',
          { title: results.title, names: list.format(names), percent },
        )
      : translate(messages, 'public.meta.plain', {
          title: results.title,
          institution: results.institution.name,
          percent,
        });

  return {
    title,
    description,
    alternates: { canonical: url },
    openGraph: {
      type: 'website',
      url,
      title,
      description,
      siteName: translate(messages, 'app.name'),
      locale: OPEN_GRAPH_LOCALE[locale],
      images: [{ url: image, width: 1200, height: 630, alt: title }],
    },
    twitter: { card: 'summary_large_image', title, description, images: [image] },
  };
}

/**
 * M-results: the public results of a published election, for anyone, no sign-in. An election that
 * is not published (any other status, unknown, not a UUID, a suspended institution) is the same
 * real 404 with the "no public results here" page, so the page never tells whether it exists.
 */
export default async function PublicResultsPage({ params }: Props) {
  const { election } = await params;
  // Throws `PublicUnavailableError` when the API does not answer: the error page, not the 404.
  const results = await fetchPublicResults(election, (await headers()).get('x-forwarded-for'));

  if (!results) notFound();

  const { locale, t } = await getI18n();
  const origin = originOf(await headers(), process.env.SITE_URL);
  const url = absoluteUrl(origin, `/results/${results.id}`);

  return (
    <PublicResultsView
      results={results}
      t={t}
      locale={locale}
      url={url}
      links={shareLinks(
        translate(getMessages(locale), 'public.meta.title', {
          title: results.title,
          institution: results.institution.name,
        }),
        url,
      )}
      reportHref={`/api/v1/public/elections/${results.id}/report`}
      imageHref={`/results/${results.id}/opengraph-image`}
      institutionHref={`/institutions/${results.institution.id}`}
    />
  );
}
