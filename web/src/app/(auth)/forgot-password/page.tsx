import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

export default function ForgotPasswordPage() {
  return (
    <AuthPage>
      <ForgotPasswordForm />
    </AuthPage>
  );
}
