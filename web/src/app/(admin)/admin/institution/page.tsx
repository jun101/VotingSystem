import { notFound } from 'next/navigation';
import { InstitutionPage } from '@/components/institution/InstitutionPage';
import { fetchInstitution, fetchInvitations, fetchSession, fetchTeam } from '@/lib/api/server';
import { I18nProvider } from '@/lib/i18n/client';
import { getMessages, pick } from '@/lib/i18n/messages';
import { getI18n } from '@/lib/i18n/server';

export const dynamic = 'force-dynamic';

/** Screen A14: the profile of the institution and, for an owner, its users and invitations. */
export default async function InstitutionRoute() {
  const session = await fetchSession();
  const profile = await fetchInstitution();

  if (session.status !== 'signed-in') notFound();

  // Signed in, but the API did not answer (timeout, 5xx, network): the error page, not a 404.
  if (!profile) throw new Error('The institution could not be read.');

  const owner = session.user.role === 'owner';
  const [members, invitations] = owner
    ? await Promise.all([fetchTeam(), fetchInvitations()])
    : [null, null];

  // An owner whose lists cannot be read gets the error page, not a note about owners.
  if (owner && (!members || !invitations)) throw new Error('The users could not be read.');

  const { locale } = await getI18n();

  return (
    <I18nProvider
      locale={locale}
      messages={pick(getMessages(locale), 'app', 'admin', 'errors', 'validation', 'institution')}
    >
      <InstitutionPage profile={profile} members={members} invitations={invitations} />
    </I18nProvider>
  );
}
