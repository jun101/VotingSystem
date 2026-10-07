import { cookies } from 'next/headers';
import { createApiClient } from './client';
import { SESSION_COOKIE } from './session';
import type { CurrentUser } from './user';

/**
 * The signed-in user, for a page that runs on the server (to redirect, or to render for
 * them). Null when nobody is signed in, the session has ended or the API did not answer.
 * It only asks when the browser sent a session cookie, and forwards that cookie alone.
 */
export async function fetchCurrentUser(): Promise<CurrentUser | null> {
  const session = (await cookies()).get(SESSION_COOKIE)?.value;

  if (!session) return null;

  try {
    const { data, response } = await createApiClient().GET('/v1/auth/me', {
      headers: { Cookie: `${SESSION_COOKIE}=${session}` },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? data.data : null;
  } catch {
    return null;
  }
}
