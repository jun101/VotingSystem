import { cookies } from 'next/headers';
import { cache } from 'react';
import { createApiClient } from './client';
import { SESSION_COOKIE } from './session';
import type { CurrentUser } from './user';

/** Who the API says is behind the session cookie of this request. */
export type SessionState =
  { status: 'signed-in'; user: CurrentUser } | { status: 'suspended' } | { status: 'signed-out' };

/**
 * The state of the session, for a page that runs on the server (to redirect, or to render
 * for the user). It only asks when the browser sent a session cookie, and forwards that
 * cookie alone. The answer is kept for the one request being rendered (the layout and the
 * page share it), never longer: `cache` of React lives for one render.
 */
export const fetchSession = cache(async (): Promise<SessionState> => {
  const session = (await cookies()).get(SESSION_COOKIE)?.value;

  if (!session) return { status: 'signed-out' };

  try {
    const { data, response } = await createApiClient().GET('/v1/auth/me', {
      headers: { Cookie: `${SESSION_COOKIE}=${session}` },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    if (response.ok && data) return { status: 'signed-in', user: data.data };

    // The institution was suspended since sign-in: the API ended the session.
    if (response.status === 403) return { status: 'suspended' };

    return { status: 'signed-out' };
  } catch {
    return { status: 'signed-out' };
  }
});

/**
 * The signed-in user, or null when nobody is signed in, the session has ended or the API
 * did not answer.
 */
export async function fetchCurrentUser(): Promise<CurrentUser | null> {
  const state = await fetchSession();

  return state.status === 'signed-in' ? state.user : null;
}
