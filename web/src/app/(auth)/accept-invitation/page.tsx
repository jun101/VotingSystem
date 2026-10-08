import type { Metadata } from 'next';
import { AcceptInvitationForm } from '@/components/auth/AcceptInvitationForm';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

// The address holds a secret: it is not sent on to any other site.
export const metadata: Metadata = { referrer: 'no-referrer' };

export default async function AcceptInvitationPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { token } = await searchParams;

  return (
    <AuthPage redirectSignedIn={false}>
      <AcceptInvitationForm token={typeof token === 'string' && token !== '' ? token : null} />
    </AuthPage>
  );
}
