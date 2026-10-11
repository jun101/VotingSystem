import createClient from 'openapi-fetch';
import { apiBaseUrl } from './client';
import { ApiError, parseApiError } from './errors';
import { XSRF_COOKIE } from './session';
import type { Ballot, BallotChanges, NewBallot } from './ballots';
import type { Candidate, CandidateChanges, NewCandidate } from './candidates';
import type {
  Checklist,
  Election,
  ElectionChanges,
  ElectionFilters,
  NewElection,
} from './elections';
import type { NewParty, Party, PartyChanges } from './parties';
import type {
  CredentialPreview,
  CredentialRun,
  CredentialState,
  GeneratedFile,
  PreviewBody,
  Reissue,
  RunBody,
} from './credentials';
import type {
  GroupList,
  NewVoter,
  Voter,
  VoterChanges,
  VoterCopyBody,
  VoterCopyResult,
  VoterFilters,
  VoterGroup,
  VoterList,
} from './voters';
import { VOTERS_PER_PAGE } from './voters';
import type { paths } from './schema';
import type { Results, SecondRound } from './results';
import type { Dashboard, ExportQuery, Turnout, TurnoutBucket } from './turnout';
import type {
  ImportMapping,
  ImportRowFilter,
  ImportRowList,
  VoterImport,
  VoterImportUpload,
} from './voterImports';
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

/** `GET /elections/{election}/checklist`: the blockers and warnings of an election. */
export async function fetchChecklist(id: string): Promise<Checklist> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/checklist', { params: { path: { election: id } } }),
  );

  return data!.data;
}

/** `POST /elections/{election}/schedule`: a draft becomes scheduled. */
export async function scheduleElection(id: string, acceptWarnings: boolean): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/schedule', {
      params: { path: { election: id } },
      body: { accept_warnings: acceptWarnings },
    }),
  );

  return data!.data;
}

/** `POST /elections/{election}/unschedule`: a scheduled election goes back to draft. */
export async function unscheduleElection(id: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/unschedule', { params: { path: { election: id } } }),
  );

  return data!.data;
}

/** `POST /elections/{election}/close`: closes an open election now; `confirmation` is its title. */
export async function closeElection(id: string, confirmation: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/close', {
      params: { path: { election: id } },
      body: { confirmation },
    }),
  );

  return data!.data;
}

/** `POST /elections/{election}/extend`: moves the end of a scheduled or open election later (UTC, ISO). */
export async function extendElection(id: string, endsAt: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/extend', {
      params: { path: { election: id } },
      body: { ends_at: endsAt },
    }),
  );

  return data!.data;
}

/**
 * `POST /elections/{election}/publish`: a closed election's results become public. `display` is
 * what the public page shows (`full` counts or only `winners` and turnout).
 */
export async function publishElection(id: string, display: 'full' | 'winners'): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/publish', {
      params: { path: { election: id } },
      body: { results_display: display },
    }),
  );

  return data!.data;
}

/** `POST /elections/{election}/unpublish`: back to closed, nothing public at once. */
export async function unpublishElection(id: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/unpublish', { params: { path: { election: id } } }),
  );

  return data!.data;
}

/** `POST /elections/{election}/archive`: a closed or published election is archived (and not public). */
export async function archiveElection(id: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/archive', { params: { path: { election: id } } }),
  );

  return data!.data;
}

/** `POST /elections/{election}/unarchive`: an archived election goes back to closed. */
export async function unarchiveElection(id: string): Promise<Election> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/unarchive', { params: { path: { election: id } } }),
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

/** `POST /ballots/{ballot}/candidates`: a candidate at the end of a ballot of a draft election. */
export async function createCandidate(ballot: string, body: NewCandidate): Promise<Candidate> {
  const { data } = await send((api) =>
    api.POST('/v1/ballots/{ballot}/candidates', { params: { path: { ballot } }, body }),
  );

  return data!.data;
}

/** `PATCH /candidates/{candidate}`: the fields given change; `ballot` moves it to the end of another. */
export async function updateCandidate(id: string, body: CandidateChanges): Promise<Candidate> {
  const { data } = await send((api) =>
    api.PATCH('/v1/candidates/{candidate}', { params: { path: { candidate: id } }, body }),
  );

  return data!.data;
}

/** `DELETE /candidates/{candidate}`: the positions after it close the gap. */
export async function deleteCandidate(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/candidates/{candidate}', { params: { path: { candidate: id } } }),
  );
}

/** `PUT /ballots/{ballot}/candidates/order`: exactly the UUIDs of the ballot's candidates, in the wanted order. */
export async function reorderCandidates(ballot: string, ids: string[]): Promise<Candidate[]> {
  const { data } = await send((api) =>
    api.PUT('/v1/ballots/{ballot}/candidates/order', {
      params: { path: { ballot } },
      body: { candidates: ids },
    }),
  );

  return data!.data;
}

/** `DELETE /candidates/{candidate}/photo`: removes the photo of a candidate of a draft election. */
export async function removeCandidatePhoto(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/candidates/{candidate}/photo', { params: { path: { candidate: id } } }),
  );
}

/**
 * `PUT /candidates/{candidate}/photo`: the picture, as one `file` part, sent by hand like the party
 * logo (the schema has no multipart body), with the same rules.
 */
export async function uploadCandidatePhoto(id: string, file: File): Promise<Candidate> {
  for (let attempt = 0; ; attempt++) {
    const body = new FormData();
    body.append('file', file);

    let response: Response;

    try {
      response = await fetch(`${apiBaseUrl()}/v1/candidates/${encodeURIComponent(id)}/photo`, {
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

    if (response.ok) return ((await response.json()) as { data: Candidate }).data;

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

/**
 * `GET /elections/{election}/voters`: one page, with the search and the group of the filters.
 * Blank filters are not sent.
 */
export async function fetchVoters(election: string, filters: VoterFilters): Promise<VoterList> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/voters', {
      params: {
        path: { election },
        query: {
          page: filters.page,
          per_page: VOTERS_PER_PAGE,
          ...(filters.q === '' ? {} : { q: filters.q }),
          ...(filters.group === '' ? {} : { group: filters.group }),
          ...(filters.credential === '' ? {} : { credential: filters.credential }),
          ...(filters.email === '' ? {} : { email: filters.email }),
          ...(!filters.voted ? {} : { voted: filters.voted }),
        },
      },
    }),
  );

  return { items: data!.data, total: data!.meta.total, page: data!.meta.page };
}

/** `POST /elections/{election}/voters`: a voter (the group is sent as its name). */
export async function createVoter(election: string, body: NewVoter): Promise<Voter> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/voters', { params: { path: { election } }, body }),
  );

  return data!.data;
}

/** `POST /elections/{election}/voter-copies`: loads voters from another election. */
export async function copyVoters(election: string, body: VoterCopyBody): Promise<VoterCopyResult> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/voter-copies', {
      params: { path: { election } },
      body,
    }),
  );

  return data!.data;
}

/** `PATCH /voters/{voter}`: the fields given change. */
export async function updateVoter(id: string, body: VoterChanges): Promise<Voter> {
  const { data } = await send((api) =>
    api.PATCH('/v1/voters/{voter}', { params: { path: { voter: id } }, body }),
  );

  return data!.data;
}

/** `DELETE /voters/{voter}`: a voter of a draft or scheduled election. */
export async function deleteVoter(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/voters/{voter}', { params: { path: { voter: id } } }));
}

/** `GET /elections/{election}/groups`: every group (at most 100) with the voter counts. */
export async function fetchGroups(election: string): Promise<GroupList> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/groups', {
      params: { path: { election }, query: { per_page: 100 } },
    }),
  );

  return {
    items: data!.data,
    votersTotal: data!.meta.voters_total,
    ungrouped: data!.meta.ungrouped,
  };
}

/** `POST /elections/{election}/groups`: an empty group. */
export async function createGroup(election: string, name: string): Promise<VoterGroup> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/groups', {
      params: { path: { election } },
      body: { name },
    }),
  );

  return data!.data;
}

/** `PATCH /groups/{group}`: renames a group; its voters follow it. */
export async function renameGroup(id: string, name: string): Promise<VoterGroup> {
  const { data } = await send((api) =>
    api.PATCH('/v1/groups/{group}', { params: { path: { group: id } }, body: { name } }),
  );

  return data!.data;
}

/** `DELETE /groups/{group}`: a group with no voter. */
export async function deleteGroup(id: string): Promise<void> {
  await send((api) => api.DELETE('/v1/groups/{group}', { params: { path: { group: id } } }));
}

/** `POST /groups/{group}/merge`: the voters move into `into`, the group is deleted. */
export async function mergeGroup(id: string, into: string): Promise<VoterGroup> {
  const { data } = await send((api) =>
    api.POST('/v1/groups/{group}/merge', { params: { path: { group: id } }, body: { into } }),
  );

  return data!.data;
}

/**
 * `POST /elections/{election}/voter-imports`: the voter file, as one `file` part. Sent by hand
 * like the pictures (the schema has no multipart body), with the same rules: the CSRF token, one
 * retry on a 419, an `ApiError` for anything that is not a success. Nothing is imported: the
 * file is checked in the background.
 */
export async function uploadVoterFile(election: string, file: File): Promise<VoterImportUpload> {
  for (let attempt = 0; ; attempt++) {
    const body = new FormData();
    body.append('file', file);

    let response: Response;

    try {
      response = await fetch(
        `${apiBaseUrl()}/v1/elections/${encodeURIComponent(election)}/voter-imports`,
        {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Accept-Language': pageLanguage(),
            'X-XSRF-TOKEN': await csrfToken(),
          },
        },
      );
    } catch (error) {
      throw error instanceof ApiError ? error : new ApiError(0, 'network');
    }

    if (response.ok) return ((await response.json()) as { data: VoterImportUpload }).data;

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

/** `GET /voter-imports/{import}`: the status, the summary and the progress. */
export async function fetchVoterImport(id: string): Promise<VoterImport> {
  const { data } = await send((api) =>
    api.GET('/v1/voter-imports/{import}', { params: { path: { import: id } } }),
  );

  return data!.data;
}

/** `GET /voter-imports/{import}/rows`: a page of the preview (50 rows). */
export async function fetchImportRows(
  id: string,
  filter: ImportRowFilter,
  page: number,
): Promise<ImportRowList> {
  const { data } = await send((api) =>
    api.GET('/v1/voter-imports/{import}/rows', {
      params: { path: { import: id }, query: { filter, page } },
    }),
  );

  return { items: data!.data, total: data!.meta.total, page: data!.meta.page ?? page };
}

/** `PATCH /voter-imports/{import}`: the fields given change column; the file is checked again. */
export async function updateImportMapping(
  id: string,
  mapping: Partial<ImportMapping>,
): Promise<VoterImport> {
  const { data } = await send((api) =>
    api.PATCH('/v1/voter-imports/{import}', {
      params: { path: { import: id } },
      // The generated type has no body for this call; the endpoint file says `mapping`.
      body: { mapping } as never,
    }),
  );

  return (data as { data: VoterImport }).data;
}

/** `POST /voter-imports/{import}/confirm`: the valid rows are written in the background. */
export async function confirmImport(id: string): Promise<VoterImport> {
  const { data } = await send((api) =>
    api.POST('/v1/voter-imports/{import}/confirm', { params: { path: { import: id } } }),
  );

  return (data as { data: VoterImport }).data;
}

/** `DELETE /voter-imports/{import}`: the file and its rows are deleted. */
export async function cancelImport(id: string): Promise<void> {
  await send((api) =>
    api.DELETE('/v1/voter-imports/{import}', { params: { path: { import: id } } }),
  );
}

/** `GET /elections/{election}/credentials`: the totals and one line per group (A11). */
export async function fetchCredentials(election: string): Promise<CredentialState> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/credentials', { params: { path: { election } } }),
  );

  return data!.data;
}

/** `GET /elections/{election}/generated-files`: the slip files of an election, newest first. */
export async function fetchGeneratedFiles(election: string): Promise<GeneratedFile[]> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/generated-files', { params: { path: { election } } }),
  );

  return data!.data;
}

/**
 * `POST /elections/{election}/credential-runs`: gives a code to the voters of the scope who have
 * none. The answer comes before the work: the files are `queued`.
 */
export async function startCredentialRun(election: string, body: RunBody): Promise<CredentialRun> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/credential-runs', {
      params: { path: { election } },
      body,
    }),
  );

  return data!.data;
}

/**
 * `POST /elections/{election}/credential-previews`: what a run would do for a scope. A reading:
 * nothing is made, nothing is sent.
 */
export async function previewCredentialRun(
  election: string,
  body: PreviewBody,
): Promise<CredentialPreview> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/credential-previews', {
      params: { path: { election } },
      body,
    }),
  );

  return data!.data;
}

/**
 * `POST /voters/{voter}/credential`: a new code for one voter, shown once, with a one-slip file.
 * The answer is the only place a code is ever sent: the caller shows it and keeps it nowhere.
 */
export async function reissueCredential(
  voter: string,
  options: { email?: boolean } = {},
): Promise<Reissue> {
  const { data } = await send((api) =>
    api.POST('/v1/voters/{voter}/credential', {
      params: { path: { voter } },
      // The body is sent only when the code is also to be emailed.
      ...(options.email ? { body: { email: true } } : {}),
    }),
  );

  return data!.data;
}

/** A downloaded file: the bytes and the name the API gave it. */
export type DownloadedFile = { blob: Blob; name: string };

/** The file name of a `Content-Disposition` header, or the fallback. */
export function dispositionName(header: string | null, fallback: string): string {
  const match = header ? /filename="?([^";]+)"?/i.exec(header) : null;

  return match?.[1] ?? fallback;
}

/**
 * `GET /generated-files/{file}/download`: the PDF as bytes, with the name the API sends. Read by
 * hand (not as a plain link) so a refusal (409, 404, 429) is an `ApiError` the page can say in
 * words, and no JSON error page replaces the screen.
 */
export async function downloadGeneratedFile(file: string): Promise<DownloadedFile> {
  let response: Response;

  try {
    response = await fetch(
      `${apiBaseUrl()}/v1/generated-files/${encodeURIComponent(file)}/download`,
      {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/pdf, application/json', 'Accept-Language': pageLanguage() },
      },
    );
  } catch {
    throw new ApiError(0, 'network');
  }

  if (!response.ok) {
    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    throw parseApiError(response.status, answer, response.headers.get('Retry-After'));
  }

  return {
    blob: await response.blob(),
    name: dispositionName(response.headers.get('Content-Disposition'), 'coupons.pdf'),
  };
}

/** `GET /elections/{election}/turnout`: how many have voted, by group and over time (never what). */
export async function fetchTurnout(election: string, bucket?: TurnoutBucket): Promise<Turnout> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/turnout', {
      params: { path: { election }, ...(bucket ? { query: { bucket } } : {}) },
      cache: 'no-store',
    }),
  );

  return data!.data;
}

/** `PUT /ballots/{ballot}/result-note`: sets the public note of a ballot's result (null or empty clears it). */
export async function saveResultNote(ballot: string, note: string | null): Promise<string | null> {
  const { data } = await send((api) =>
    api.PUT('/v1/ballots/{ballot}/result-note', {
      params: { path: { ballot } },
      body: { note },
    }),
  );

  return data!.data.note;
}

/** `POST /elections/{election}/second-round`: a draft election from a tied ballot. */
export async function createSecondRound(election: string, ballot: string): Promise<SecondRound> {
  const { data } = await send((api) =>
    api.POST('/v1/elections/{election}/second-round', {
      params: { path: { election } },
      body: { ballot },
    }),
  );

  return data!.data;
}

/** `GET /elections/{election}/results`, read again after a change. */
export async function fetchResults(election: string): Promise<Results> {
  const { data } = await send((api) =>
    api.GET('/v1/elections/{election}/results', {
      params: { path: { election } },
      cache: 'no-store',
    }),
  );

  return data!.data;
}

/** `GET /dashboard`: the counts by status, the open elections with their turnout, the to-do list. */
export async function fetchDashboard(): Promise<Dashboard> {
  const { data } = await send((api) => api.GET('/v1/dashboard', { cache: 'no-store' }));

  return data!.data;
}

/**
 * `GET /elections/{election}/voters/export`: the voters as an Excel or CSV file, with the name
 * the API gives it. Read by hand so a refusal (429, 404) is an `ApiError` the page can say.
 */
export async function downloadVoterExport(
  election: string,
  query: ExportQuery,
): Promise<DownloadedFile> {
  const params = new URLSearchParams({ format: query.format, voted: query.voted });

  if (query.group !== '') params.set('group', query.group);
  if (query.q !== '') params.set('q', query.q);

  let response: Response;

  try {
    response = await fetch(
      `${apiBaseUrl()}/v1/elections/${encodeURIComponent(election)}/voters/export?${params}`,
      {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          Accept:
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, text/csv, application/json',
          'Accept-Language': pageLanguage(),
        },
      },
    );
  } catch {
    throw new ApiError(0, 'network');
  }

  if (!response.ok) {
    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    throw parseApiError(response.status, answer, response.headers.get('Retry-After'));
  }

  return {
    blob: await response.blob(),
    name: dispositionName(
      response.headers.get('Content-Disposition'),
      query.format === 'csv' ? 'electeurs.csv' : 'electeurs.xlsx',
    ),
  };
}

/**
 * `GET /elections/{election}/report`: the results report as a PDF, with the name the API gives it.
 * Read by hand so a refusal (409, 429) is an `ApiError` the page can say in words.
 */
export async function downloadElectionReport(election: string): Promise<DownloadedFile> {
  let response: Response;

  try {
    response = await fetch(`${apiBaseUrl()}/v1/elections/${encodeURIComponent(election)}/report`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/pdf, application/json', 'Accept-Language': pageLanguage() },
    });
  } catch {
    throw new ApiError(0, 'network');
  }

  if (!response.ok) {
    let answer: unknown = null;

    try {
      answer = await response.json();
    } catch {
      // Not JSON: an `unknown` error.
    }

    throw parseApiError(response.status, answer, response.headers.get('Retry-After'));
  }

  return {
    blob: await response.blob(),
    name: dispositionName(response.headers.get('Content-Disposition'), 'report.pdf'),
  };
}
