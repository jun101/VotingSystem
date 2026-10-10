import createClient from 'openapi-fetch';
import { apiBaseUrl } from './client';
import { ApiError, parseApiError } from './errors';
import { XSRF_COOKIE } from './session';
import type { Ballot, BallotChanges, NewBallot } from './ballots';
import type { Election, ElectionChanges, ElectionFilters, NewElection } from './elections';
import type { NewParty, Party, PartyChanges } from './parties';
import type { paths } from './schema';
import type {
  CurrentUser,
  InstitutionProfile,
  InvitedRole,
  PendingInvitation,
  ProfileChanges,
  RegisterBody,
  TwoFactorRequired,
  TwoFactorSetup,
} from './user';

/*
 * The calls the pages make from the browser (docs/api/auth/). Every one of them:
 *
 * - goes to the same origin, with the session cookie (`credentials: 'same-origin'`);
 * - sends `Accept-Language` (the language of the page) and, when it changes something, the
 *   CSRF token as `X-XSRF-TOKEN`, copied from the cookie the API set. The cookie is fetched
 *   once, by `GET /auth/csrf`, when the browser has none; a 419 fetches it again and
 *   repeats the call once (the session had ended);
 * - throws an `ApiError` for any answer that is not a success, a network failure included.
 *
 * Nothing is kept in the browser's storage: the cookies are all there is.
 */

type Client = ReturnType<typeof createClient<paths>>;

let client: Client | null = null;

function getClient(): Client {
  if (client) return client;

  client = createClient<paths>({ baseUrl: apiBaseUrl(), credentials: 'same-origin' });
  client.use({
    async onRequest({ request }) {
      request.headers.set('Accept-Language', pageLanguage());

      if (request.method !== 'GET' && request.method !== 'HEAD') {
        request.headers.set('X-XSRF-TOKEN', await csrfToken());
      }

      return request;
    },
  });

  return client;
}

function pageLanguage(): string {
  return document.documentElement.lang || 'fr';
}

function readCookie(name: string): string | null {
  for (const part of document.cookie.split('; ')) {
    const at = part.indexOf('=');

    if (at > 0 && part.slice(0, at) === name) return decodeURIComponent(part.slice(at + 1));
  }

  return null;
}

let fetching: Promise<void> | null = null;

/** `GET /auth/csrf`: starts the session if there is none and sets the CSRF cookie. */
function refreshCsrf(): Promise<void> {
  fetching ??= fetch(`${apiBaseUrl()}/v1/auth/csrf`, {
    credentials: 'same-origin',
    headers: { 'Accept-Language': pageLanguage() },
  })
    .then(
      (response) => {
        // An answer that is not a success (429, 5xx) is the error the person reads, not a
        // reason to send the call with an empty token.
        if (response.ok) return undefined;

        return response.text().then((text) => {
          let body: unknown = null;

          try {
            body = JSON.parse(text);
          } catch {
            // Not JSON: an `unknown` error.
          }

          throw parseApiError(response.status, body, response.headers.get('Retry-After'));
        });
      },
      () => {
        throw new ApiError(0, 'network');
      },
    )
    .finally(() => {
      fetching = null;
    });

  return fetching;
}

async function csrfToken(): Promise<string> {
  let token = readCookie(XSRF_COOKIE);

  if (!token) {
    await refreshCsrf();
    token = readCookie(XSRF_COOKIE);
  }

  return token ?? '';
}

type Outcome = { data?: unknown; error?: unknown; response: Response };

async function send<R extends Outcome>(call: (api: Client) => Promise<R>): Promise<R> {
  for (let attempt = 0; ; attempt++) {
    let outcome: R;

    try {
      outcome = await call(getClient());
    } catch (error) {
      // The CSRF call can have failed on its own (rate limit, server error).
      throw error instanceof ApiError ? error : new ApiError(0, 'network');
    }

    if (outcome.response.ok) return outcome;

    const failure = parseApiError(
      outcome.response.status,
      outcome.error,
      outcome.response.headers.get('Retry-After'),
    );

    if (failure.code === 'csrf_mismatch' && attempt === 0) {
      await refreshCsrf();

      continue;
    }

    throw failure;
  }
}

export async function register(body: RegisterBody): Promise<CurrentUser> {
  const { data } = await send((api) => api.POST('/v1/auth/register', { body }));

  return data!.data;
}

/**
 * `POST /auth/login`. A user with two-factor authentication is not signed in yet: the answer is
 * `{ two_factor_required: true }` and the sign-in is finished with `twoFactorChallenge`.
 */
export async function login(body: {
  email: string;
  password: string;
}): Promise<CurrentUser | TwoFactorRequired> {
  const { data } = await send((api) => api.POST('/v1/auth/login', { body }));

  return data!.data;
}

/** `POST /auth/two-factor-challenge`: the second step of signing in, with a code or a recovery code. */
export async function twoFactorChallenge(
  body: { code: string } | { recovery_code: string },
): Promise<CurrentUser> {
  const { data } = await send((api) => api.POST('/v1/auth/two-factor-challenge', { body }));

  return data!.data;
}

/** `POST /auth/two-factor/setup`: a new secret, after the password is checked. */
export async function startTwoFactorSetup(password: string): Promise<TwoFactorSetup> {
  const { data } = await send((api) =>
    api.POST('/v1/auth/two-factor/setup', { body: { password } }),
  );

  return data!.data;
}

/** `POST /auth/two-factor/confirm`: turns it on; the eight recovery codes, shown once. */
export async function confirmTwoFactor(code: string): Promise<string[]> {
  const { data } = await send((api) => api.POST('/v1/auth/two-factor/confirm', { body: { code } }));

  return data!.data.recovery_codes;
}

/**
 * The second factor asked with the password: a `code` of the authenticator application or a
 * `recovery_code` (one of the two).
 */
export type SecondFactor = { code: string } | { recovery_code: string };

/** `POST /auth/two-factor/disable`: the password and a current second factor. */
export async function disableTwoFactor(password: string, factor: SecondFactor): Promise<void> {
  await send((api) => api.POST('/v1/auth/two-factor/disable', { body: { password, ...factor } }));
}

/** `POST /auth/two-factor/recovery-codes`: eight new codes; the old ones stop working. */
export async function renewRecoveryCodes(
  password: string,
  factor: SecondFactor,
): Promise<string[]> {
  const { data } = await send((api) =>
    api.POST('/v1/auth/two-factor/recovery-codes', { body: { password, ...factor } }),
  );

  return data!.data.recovery_codes;
}

/**
 * `POST /users/{user}/two-factor/reset`: an owner turns off another user's two-factor, with
 * the owner's own password, and the owner's own second factor when the owner has two-factor on.
 */
export async function resetUserTwoFactor(
  id: string,
  password: string,
  factor?: SecondFactor,
): Promise<void> {
  await send((api) =>
    api.POST('/v1/users/{user}/two-factor/reset', {
      params: { path: { user: id } },
      body: { password, ...factor },
    }),
  );
}

/** `PATCH /auth/me`: the language of the admin area, stored on the user. */
export async function updateLanguage(language: 'fr' | 'en'): Promise<CurrentUser> {
  const { data } = await send((api) => api.PATCH('/v1/auth/me', { body: { language } }));

  return data!.data;
}

export async function logout(): Promise<void> {
  await send((api) => api.POST('/v1/auth/logout'));
}

export async function verifyEmail(token: string): Promise<void> {
  await send((api) => api.POST('/v1/auth/verify-email', { body: { token } }));
}

export async function resendVerification(): Promise<void> {
  await send((api) => api.POST('/v1/auth/verify-email/resend'));
}

export async function forgotPassword(email: string): Promise<void> {
  await send((api) => api.POST('/v1/auth/forgot-password', { body: { email } }));
}

export async function resetPassword(body: { token: string; password: string }): Promise<void> {
  await send((api) => api.POST('/v1/auth/reset-password', { body }));
}

export async function acceptInvitation(body: {
  token: string;
  name: string;
  password: string;
}): Promise<CurrentUser> {
  const { data } = await send((api) => api.POST('/v1/auth/accept-invitation', { body }));

  return data!.data;
}

/** `PATCH /institution`: the profile (an owner). */
export async function updateInstitution(body: ProfileChanges): Promise<InstitutionProfile> {
  const { data } = await send((api) => api.PATCH('/v1/institution', { body }));

  return data!.data;
}

/**
 * `PUT /institution/logo`: the picture, as one `file` part. Sent by hand (the schema has no
 * multipart body); it follows the same rules as the other calls: the CSRF token, one retry on a
 * 419, an `ApiError` for anything that is not a success.
 */
export async function uploadLogo(file: File): Promise<InstitutionProfile> {
  for (let attempt = 0; ; attempt++) {
    const body = new FormData();
    body.append('file', file);

    let response: Response;

    try {
      response = await fetch(`${apiBaseUrl()}/v1/institution/logo`, {
        method: 'PUT',
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Accept-Language': pageLanguage(),
          'X-XSRF-TOKEN': await csrfToken(),
        },
      });
    } catch (error) {
      throw error instanceof ApiError ? error : new ApiError(0, 'network');
    }

    if (response.ok) return ((await response.json()) as { data: InstitutionProfile }).data;

    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    const failure = parseApiError(response.status, answer, response.headers.get('Retry-After'));

    if (failure.code === 'csrf_mismatch' && attempt === 0) {
      await refreshCsrf();

      continue;
    }

    throw failure;
  }
}

export async function removeLogo(): Promise<void> {
  await send((api) => api.DELETE('/v1/institution/logo'));
}

export async function inviteUser(body: {
  email: string;
  role: InvitedRole;
}): Promise<PendingInvitation> {
  const { data } = await send((api) => api.POST('/v1/invitations', { body }));

  return data!.data;
}

export async function cancelInvitation(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/invitations/{invitation}', { params: { path: { invitation: id } } }),
  );
}

export async function removeUser(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/users/{user}', { params: { path: { user: id } } }));
}

/** `GET /elections`: one page of 100 cards under the filters, and the total. */
export async function fetchElectionsPage(
  filters: ElectionFilters,
  page: number,
): Promise<{ items: Election[]; total: number }> {
  const { data } = await send((api) =>
    api.GET('/v1/elections', {
      params: {
        query: {
          per_page: 100,
          page,
          ...(filters.status ? { status: filters.status } : {}),
          ...(filters.year ? { year: String(filters.year) } : {}),
        },
      },
    }),
  );

  return { items: data!.data, total: data!.meta.total };
}

/** `POST /elections`: a draft of the caller's institution. */
export async function createElection(body: NewElection): Promise<Election> {
  const { data } = await send((api) => api.POST('/v1/elections', { body }));

  return data!.data;
}

/** `PATCH /elections/{election}`: the fields given change, a draft only. */
export async function updateElection(id: string, body: ElectionChanges): Promise<Election> {
  const { data } = await send((api) =>
    api.PATCH('/v1/elections/{election}', { params: { path: { election: id } }, body }),
  );

  return data!.data;
}

/** `DELETE /elections/{election}`: a draft, for good. */
export async function deleteElection(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/elections/{election}', { params: { path: { election: id } } }),
  );
}

/** `POST /elections/{election}/duplicate`: a new draft with the settings of this one. */
export async function duplicateElection(id: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/duplicate', { params: { path: { election: id } } }),
  );

  return data!.data;
}

/** `DELETE /elections/{election}/cover`: removes the cover of a draft. */
export async function removeElectionCover(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/elections/{election}/cover', { params: { path: { election: id } } }),
  );
}

/**
 * `PUT /elections/{election}/cover`: the picture, as one `file` part. Sent by hand like the logo
 * (the schema has no multipart body), with the same rules: the CSRF token, one retry on a 419,
 * an `ApiError` for anything that is not a success.
 */
export async function uploadElectionCover(id: string, file: File): Promise<Election> {
  for (let attempt = 0; ; attempt++) {
    const body = new FormData();
    body.append('file', file);

    let response: Response;

    try {
      response = await fetch(`${apiBaseUrl()}/v1/elections/${encodeURIComponent(id)}/cover`, {
        method: 'PUT',
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Accept-Language': pageLanguage(),
          'X-XSRF-TOKEN': await csrfToken(),
        },
      });
    } catch (error) {
      throw error instanceof ApiError ? error : new ApiError(0, 'network');
    }

    if (response.ok) return ((await response.json()) as { data: Election }).data;

    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    const failure = parseApiError(response.status, answer, response.headers.get('Retry-After'));

    if (failure.code === 'csrf_mismatch' && attempt === 0) {
      await refreshCsrf();

      continue;
    }

    throw failure;
  }
}

/** `GET /elections/{election}/ballots`: every ballot of the election, in order (at most 50). */
export async function fetchBallots(election: string): Promise<Ballot[]> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/ballots', {
      params: { path: { election }, query: { per_page: 100 } },
    }),
  );

  return data!.data;
}

/** `POST /elections/{election}/ballots`: a ballot at the end of a draft election. */
export async function createBallot(election: string, body: NewBallot): Promise<Ballot> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/ballots', { params: { path: { election } }, body }),
  );

  return data!.data;
}

/** `PATCH /ballots/{ballot}`: the fields given change. */
export async function updateBallot(id: string, body: BallotChanges): Promise<Ballot> {
  const { data } = await send((api) =>
    api.PATCH('/v1/ballots/{ballot}', { params: { path: { ballot: id } }, body }),
  );

  return data!.data;
}

/** `DELETE /ballots/{ballot}`: the positions after it close the gap. */
export async function deleteBallot(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/ballots/{ballot}', { params: { path: { ballot: id } } }));
}

/** `PUT /elections/{election}/ballots/order`: exactly the UUIDs of the election, in the wanted order. */
export async function reorderBallots(election: string, ids: string[]): Promise<Ballot[]> {
  const { data } = await send((api) =>
    api.PUT('/v1/elections/{election}/ballots/order', {
      params: { path: { election } },
      body: { ballots: ids },
    }),
  );

  return data!.data;
}

/** `GET /elections/{election}/parties`: every party of the election (at most 30). */
export async function fetchParties(election: string): Promise<Party[]> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/parties', {
      params: { path: { election }, query: { per_page: 100 } },
    }),
  );

  return data!.data;
}

/** `POST /elections/{election}/parties`: a party of a draft election. */
export async function createParty(election: string, body: NewParty): Promise<Party> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/parties', { params: { path: { election } }, body }),
  );

  return data!.data;
}

/** `PATCH /parties/{party}`: the fields given change. */
export async function updateParty(id: string, body: PartyChanges): Promise<Party> {
  const { data } = await send((api) =>
    api.PATCH('/v1/parties/{party}', { params: { path: { party: id } }, body }),
  );

  return data!.data;
}

/** `DELETE /parties/{party}/logo`: removes the logo of a party of a draft election. */
export async function removePartyLogo(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/parties/{party}/logo', { params: { path: { party: id } } }));
}

/**
 * `PUT /parties/{party}/logo`: the picture, as one `file` part, sent by hand like the election
 * cover (the schema has no multipart body), with the same rules.
 */
export async function uploadPartyLogo(id: string, file: File): Promise<Party> {
  for (let attempt = 0; ; attempt++) {
    const body = new FormData();
    body.append('file', file);

    let response: Response;

    try {
      response = await fetch(`${apiBaseUrl()}/v1/parties/${encodeURIComponent(id)}/logo`, {
        method: 'PUT',
        body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Accept-Language': pageLanguage(),
          'X-XSRF-TOKEN': await csrfToken(),
        },
      });
    } catch (error) {
      throw error instanceof ApiError ? error : new ApiError(0, 'network');
    }

    if (response.ok) return ((await response.json()) as { data: Party }).data;

    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    const failure = parseApiError(response.status, answer, response.headers.get('Retry-After'));

    if (failure.code === 'csrf_mismatch' && attempt === 0) {
      await refreshCsrf();

      continue;
    }

    throw failure;
  }
}

/** `DELETE /parties/{party}`: its candidates stay and become independent. */
export async function deleteParty(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/parties/{party}', { params: { path: { party: id } } }));
}
