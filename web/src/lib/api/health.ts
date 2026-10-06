import { createApiClient } from './client';

export type HealthReport =
  { reachable: true; database: boolean; redis: boolean; time: string } | { reachable: false };

type Options = {
  /** Address of the visitor, so the API's rate limit counts visitors and not our server. */
  forwardedFor?: string | null;
  fetch?: typeof fetch;
  timeoutMs?: number;
};

/**
 * GET /api/v1/health. Never throws: an API that does not answer, or that answers 503
 * because a service it needs is down, is reported as not reachable.
 */
export async function fetchHealth(options: Options = {}): Promise<HealthReport> {
  try {
    const client = createApiClient(options.fetch ? { fetch: options.fetch } : {});

    const { data } = await client.GET('/v1/health', {
      headers: options.forwardedFor ? { 'X-Forwarded-For': options.forwardedFor } : {},
      cache: 'no-store',
      signal: AbortSignal.timeout(options.timeoutMs ?? 4000),
    });

    if (!data || data.data.status !== 'ok') return { reachable: false };

    return {
      reachable: true,
      database: data.data.checks.database === 'ok',
      redis: data.data.checks.redis === 'ok',
      time: data.data.time,
    };
  } catch {
    return { reachable: false };
  }
}
