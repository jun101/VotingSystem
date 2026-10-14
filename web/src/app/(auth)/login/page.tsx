import { LoginForm } from '@/components/auth/LoginForm';
import { isSchoolMode } from '@/lib/deployment';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { reset, suspended } = await searchParams;

  return (
    <AuthPage>
      <LoginForm reset={reset === '1'} suspended={suspended === '1'} school={isSchoolMode()} />
    </AuthPage>
  );
}
