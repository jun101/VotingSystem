import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { notFound } from 'next/navigation';
import { isSchoolMode } from '@/lib/deployment';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

export default function ForgotPasswordPage() {
  // School mode has no email: this page does not exist there (docs/api/README.md section 7a).
  if (isSchoolMode()) notFound();

  return (
    <AuthPage>
      <ForgotPasswordForm />
    </AuthPage>
  );
}
