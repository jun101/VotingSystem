import type { Metadata } from 'next';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import { notFound } from 'next/navigation';
import { isSchoolMode } from '@/lib/deployment';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

// The address holds a secret: it is not sent on to any other site.
export const metadata: Metadata = { referrer: 'no-referrer' };

export default async function ResetPasswordPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  // School mode has no email: this page does not exist there (docs/api/README.md section 7a).
  if (isSchoolMode()) notFound();

  const { token } = await searchParams;

  return (
    <AuthPage redirectSignedIn={false}>
      <ResetPasswordForm token={typeof token === 'string' && token !== '' ? token : null} />
    </AuthPage>
  );
}
