import { apiBaseUrl } from './client';
import { ApiError, parseApiError } from './errors';
import type { operations } from './schema';

/*
 * The calls of the voting pages (docs/api/vote/). The voter has no account: the session is the
 * HttpOnly cookie `vote_session`, set and read by the browser alone (`credentials`), never by this code.
 * No token, no code and no choice is written to a storage, an address or a log here.
 */

type Body<K extends keyof operations, S extends number> = operations[K] extends {
  responses: Record<S, { content: { 'application/json': infer B } }>;
}
  ? B
  : never;

/** `GET /vote/{election}`: what a voter may know before entering a code. */
export type VoteInfo = Body<'vote.info', 200> extends { data: infer D } ? D : never;
export type VotePhase = VoteInfo['phase'];

/** `GET /vote/{election}/ballots`: the voter's name and group, and their ballots. */
export type VoterBallots = Body<'vote.ballots', 200> extends { data: infer D } ? D : never;
export type VoteBallot = VoterBallots['ballots'][number];
export type VoteCandidate = VoteBallot['candidates'][number];

/** An API error that may carry the phase of an `election_not_open` answer. */
export class VoteError extends ApiError {
  constructor(
    error: ApiError,
    readonly phase: VotePhase | null,
  ) {
    super(error.status, error.code, error.fields, error.reference, error.retryAfter, error.items);
    this.name = 'VoteError';
  }
}

function phaseOf(body: unknown): VotePhase | null {
  const error =
    typeof body === 'object' && body !== null ? (body as { error?: unknown }).error : null;
  const phase =
    typeof error === 'object' && error !== null ? (error as { phase?: unknown }).phase : null;

  return phase === 'before' || phase === 'open' || phase === 'closed' ? phase : null;
}

async function call<T>(
  election: string,
  path: string,
  init: { method: 'GET' | 'POST' | 'DELETE'; body?: unknown },
): Promise<{ status: number; data: T }> {
  let response: Response;

  try {
    response = await fetch(`${apiBaseUrl()}/v1/vote/${election}${path}`, {
      method: init.method,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'Accept-Language': document.documentElement.lang || 'fr',
        ...(init.body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      ...(init.body === undefined ? {} : { body: JSON.stringify(init.body) }),
    });
  } catch {
    throw new VoteError(new ApiError(0, 'network'), null);
  }

  let body: unknown = null;

  try {
    body = response.status === 204 ? null : await response.json();
  } catch {
    // Not JSON: an `unknown` error below, or nothing to read on a success.
  }

  if (!response.ok) {
    throw new VoteError(
      parseApiError(response.status, body, response.headers.get('Retry-After')),
      phaseOf(body),
    );
  }

  return { status: response.status, data: (body as { data: T } | null)?.data as T };
}

export async function fetchInfo(election: string): Promise<VoteInfo> {
  return (await call<VoteInfo>(election, '', { method: 'GET' })).data;
}

/** Opens the session (the cookie is set by the answer). */
export async function openSession(election: string, code: string): Promise<void> {
  await call<{ expires_at: string }>(election, '/session', { method: 'POST', body: { code } });
}

/** Ends the session ("not me", "next voter"); the same answer when there is none. */
export async function endSession(election: string): Promise<void> {
  await call<null>(election, '/session', { method: 'DELETE' });
}

export async function fetchBallots(election: string): Promise<VoterBallots> {
  return (await call<VoterBallots>(election, '/ballots', { method: 'GET' })).data;
}

/** Records the vote. 201 the first time, 200 when the same session sends it again: both are a success. */
export async function submitVote(
  election: string,
  ballots: { ballot: string; candidates: string[] }[],
): Promise<{ voted_at: string }> {
  return (
    await call<{ voted_at: string }>(election, '/submit', { method: 'POST', body: { ballots } })
  ).data;
}
