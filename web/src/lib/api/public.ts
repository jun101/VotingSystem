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
 * The public results of an election, on the server, without a cookie of the visitor: the API's
 * answer is the same for everyone. Kept for the render being done (the language, the metadata and
 * the page share it). Null for anything that is not a published election of an institution that is
 * not suspended (the same 404 for all) and when the API does not answer.
 */
export const fetchPublicResults = cache(async (election: string): Promise<PublicResults | null> => {
  if (!isUuid(election)) return null;

  try {
    const { data, response } = await createApiClient().GET(
      '/v1/public/elections/{election}/results',
      {
        params: { path: { election } },
        cache: 'no-store',
        signal: AbortSignal.timeout(4000),
      },
    );

    return response.ok && data ? data.data : null;
  } catch {
    return null;
  }
});

/** The public profile of an institution, null when unknown, suspended or the API does not answer. */
export const fetchPublicInstitution = cache(
  async (institution: string): Promise<PublicInstitution | null> => {
    if (!isUuid(institution)) return null;

    try {
      const { data, response } = await createApiClient().GET(
        '/v1/public/institutions/{institution}',
        {
          params: { path: { institution } },
          cache: 'no-store',
          signal: AbortSignal.timeout(4000),
        },
      );

      return response.ok && data ? data.data : null;
    } catch {
      return null;
    }
  },
);
