import type { Metadata } from 'next';
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
  const profile = await fetchPublicInstitution(institution);

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
  const profile = await fetchPublicInstitution(institution);

  if (!profile) notFound();

  const { locale, t } = await getI18n();

  return <InstitutionView profile={profile} t={t} locale={locale} />;
}
