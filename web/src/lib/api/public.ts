import { cache } from 'react';
import { createApiClient } from './client';
import type { operations } from './schema';
import { isUuid } from './voteInfo';

/** `GET /public/elections/{election}/results`: the `data` of the public results (docs/api/publication/). */
export type PublicResults =
  operations['publication.publicResults']['responses'][200]['content']['application/json']['data'];

export type PublicBallot = PublicResults['ballots'][number];
export type PublicCandidate = PublicBallot['candidates'][number];

/** `GET /public/institutions/{institution}`: the profile and its published elections. */
export type PublicInstitution =
  operations['publication.publicInstitution']['responses'][200]['content']['application/json']['data'];

export type PublicElectionItem = PublicInstitution['elections'][number];

/**
 * The API did not give an answer that says "found" or "not found": the rate limit (429), a server
 * error (5xx), a timeout or no connection. The page shows its "try again" error page, never the
 * "no public results" one, which is only for a real not-found.
 */
export class PublicUnavailableError extends Error {
  constructor(readonly status: number | null) {
    super(`The public API did not answer (${status ?? 'no answer'}).`);
    this.name = 'PublicUnavailableError';
  }
}

/**
 * One public read: the `data` on 200, null on a 404 (and on 400 or 422, which the API answers for
 * what is not a UUID), a thrown `PublicUnavailableError` on anything else. The visitor's address is
 * forwarded so the API's limit counts the visitor, not the web server (as `fetchVoteInfo` does).
 */
async function readPublic<T>(
  load: (init: { headers: Record<string, string>; signal: AbortSignal }) => Promise<{
    data?: { data: T };
    response: Response;
  }>,
  forwardedFor: string | null,
): Promise<T | null> {
  let result: Awaited<ReturnType<typeof load>>;

  try {
    result = await load({
      headers: forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {},
      signal: AbortSignal.timeout(4000),
    });
  } catch {
    throw new PublicUnavailableError(null);
  }

  const { data, response } = result;

  if (response.ok && data) return data.data;
  if (response.status === 404) return null;

  throw new PublicUnavailableError(response.status);
}

/**
 * The public results of an election, on the server, without a cookie of the visitor: the API's
 * answer is the same for everyone. Kept for the render being done (the language, the metadata and
 * the page share it). Null for anything that is not a published election of an institution that is
 * not suspended (the same 404 for all); throws `PublicUnavailableError` when the API does not
 * answer (limit, error, timeout).
 */
export const fetchPublicResults = cache(
  async (election: string, forwardedFor: string | null = null): Promise<PublicResults | null> => {
    if (!isUuid(election)) return null;

    return readPublic<PublicResults>(
      async (init) =>
        (await createApiClient().GET('/v1/public/elections/{election}/results', {
          params: { path: { election } },
          cache: 'no-store',
          ...init,
        })) as never,
      forwardedFor,
    );
  },
);

/**
 * The public profile of an institution: null when unknown or suspended; throws
 * `PublicUnavailableError` when the API does not answer.
 */
export const fetchPublicInstitution = cache(
  async (
    institution: string,
    forwardedFor: string | null = null,
  ): Promise<PublicInstitution | null> => {
    if (!isUuid(institution)) return null;

    return readPublic<PublicInstitution>(
      async (init) =>
        (await createApiClient().GET('/v1/public/institutions/{institution}', {
          params: { path: { institution } },
          cache: 'no-store',
          ...init,
        })) as never,
      forwardedFor,
    );
  },
);
