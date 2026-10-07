import { LoginForm } from '@/components/auth/LoginForm';
import { AuthPage } from '../authPage';

export const dynamic = 'force-dynamic';

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const { reset, suspended, platform } = await searchParams;

  return (
    <AuthPage>
      <LoginForm
        reset={reset === '1'}
        suspended={suspended === '1'}
        platformAdmin={platform === '1'}
      />
    </AuthPage>
  );
}
