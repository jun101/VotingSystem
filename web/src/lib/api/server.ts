import { cookies } from 'next/headers';
import { cache } from 'react';
import { createApiClient } from './client';
import type { Ballot } from './ballots';
import type { Party } from './parties';
import type { Election, ElectionFilters, ElectionList } from './elections';
import { SESSION_COOKIE } from './session';
import type {
  CurrentUser,
  InstitutionProfile,
  Listing,
  PendingInvitation,
  TeamMember,
  TwoFactorState,
} from './user';

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

/** What a page of the admin area asks the API on the server, with the session cookie alone. */
async function authorizedGet<T>(
  read: (headers: { Cookie: string }) => Promise<T | null>,
): Promise<T | null> {
  const session = (await cookies()).get(SESSION_COOKIE)?.value;

  if (!session) return null;

  try {
    return await read({ Cookie: `${SESSION_COOKIE}=${session}` });
  } catch {
    return null;
  }
}

/** The profile of the signed-in user's institution, or null when the API does not give it. */
export const fetchInstitution = cache(async (): Promise<InstitutionProfile | null> =>
  authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/institution', {
      headers,
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? data.data : null;
  }),
);

/** The users of the institution (an owner only), the first 100. */
export const fetchTeam = cache(async (): Promise<Listing<TeamMember> | null> =>
  authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/users', {
      headers,
      params: { query: { per_page: 100 } },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? { items: data.data, total: data.meta.total } : null;
  }),
);

/** The invitations not yet accepted (an owner only), the first 100. */
export const fetchInvitations = cache(async (): Promise<Listing<PendingInvitation> | null> =>
  authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/invitations', {
      headers,
      params: { query: { per_page: 100 } },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? { items: data.data, total: data.meta.total } : null;
  }),
);

/** The signed-in user's own two-factor state, or null when the API does not give it. */
export const fetchTwoFactor = cache(async (): Promise<TwoFactorState | null> =>
  authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/auth/two-factor', {
      headers,
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? data.data : null;
  }),
);

/** The first 100 elections of the institution under these filters, or null when the API does not give them. */
export async function fetchElections(filters: ElectionFilters): Promise<ElectionList | null> {
  return authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/elections', {
      headers,
      params: {
        query: {
          per_page: 100,
          ...(filters.status ? { status: filters.status } : {}),
          ...(filters.year ? { year: String(filters.year) } : {}),
        },
      },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    if (!response.ok || !data) return null;

    return {
      items: data.data,
      total: data.meta.total,
      counts: data.meta.counts,
      years: data.meta.years,
    };
  });
}

/**
 * One election of the institution. `'missing'` when the API answers 404 (unknown, not a UUID or
 * another institution's: the same answer), null when it does not answer at all.
 */
export async function fetchElection(id: string): Promise<Election | 'missing' | null> {
  return authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/elections/{election}', {
      headers,
      params: { path: { election: id } },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    if (response.status === 404) return 'missing';

    return response.ok && data ? data.data : null;
  });
}

/** The ballots of an election of the institution, in order, or null when the API does not give them. */
export async function fetchElectionBallots(id: string): Promise<Ballot[] | null> {
  return authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/elections/{election}/ballots', {
      headers,
      params: { path: { election: id }, query: { per_page: 100 } },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? data.data : null;
  });
}

/** The parties of an election of the institution, or null when the API does not give them. */
export async function fetchElectionParties(id: string): Promise<Party[] | null> {
  return authorizedGet(async (headers) => {
    const { data, response } = await createApiClient().GET('/v1/elections/{election}/parties', {
      headers,
      params: { path: { election: id }, query: { per_page: 100 } },
      cache: 'no-store',
      signal: AbortSignal.timeout(4000),
    });

    return response.ok && data ? data.data : null;
  });
}
