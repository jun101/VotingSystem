import { RegisterForm } from '@/components/auth/RegisterForm';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

export default function RegisterPage() {
  return (
    <AuthPage>
      <RegisterForm />
    </AuthPage>
  );
}
