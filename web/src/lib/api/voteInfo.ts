import { cache } from 'react';
import { createApiClient } from './client';
import type { VoteInfo } from './vote';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isUuid(value: string): boolean {
  return UUID.test(value);
}

/**
 * `GET /vote/{election}` for a page that runs on the server. Kept for the one render being
 * done, so the root layout (language), the voting layout and the page ask once. Null when
 * there is no such election (a draft, unknown) or the API did not answer.
 */
export const fetchVoteInfo = cache(
  async (election: string, forwardedFor: string | null): Promise<VoteInfo | null> => {
    if (!isUuid(election)) return null;

    try {
      const { data, response } = await createApiClient().GET('/v1/vote/{election}', {
        params: { path: { election } },
        headers: forwardedFor ? { 'X-Forwarded-For': forwardedFor } : {},
        cache: 'no-store',
        signal: AbortSignal.timeout(4000),
      });

      return response.ok && data ? data.data : null;
    } catch {
      return null;
    }
  },
);
