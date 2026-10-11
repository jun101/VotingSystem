import type { Metadata } from 'next';
import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import { InstitutionView } from '@/components/public/InstitutionView';
import { fetchPublicInstitution } from '@/lib/api/public';
import { translate } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';
import { getMessages } from '@/lib/i18n/messages';

export const dynamic = 'force-dynamic';

type Props = { params: Promise<{ institution: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { institution } = await params;
  const forwardedFor = (await headers()).get('x-forwarded-for');
  let profile: Awaited<ReturnType<typeof fetchPublicInstitution>> = null;

  try {
    profile = await fetchPublicInstitution(institution, forwardedFor);
  } catch {
    // The page itself raises the error; the metadata only stays out of the way.
  }

  if (!profile) return { robots: { index: false, follow: false } };

  const { locale } = await getI18n();
  const messages = getMessages(locale);

  return {
    title: translate(messages, 'public.meta.institutionTitle', { name: profile.name }),
    description: translate(messages, 'public.meta.institution', { name: profile.name }),
  };
}

/** The public profile of an institution and the elections it published; an unknown or suspended one is a 404. */
export default async function InstitutionPage({ params }: Props) {
  const { institution } = await params;
  // Throws `PublicUnavailableError` when the API does not answer: the error page, not the 404.
  const profile = await fetchPublicInstitution(
    institution,
    (await headers()).get('x-forwarded-for'),
  );

  if (!profile) notFound();

  const { locale, t } = await getI18n();

  return <InstitutionView profile={profile} t={t} locale={locale} />;
}
