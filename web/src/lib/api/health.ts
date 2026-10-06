import { createApiClient } from './client';

/** What a row of the status card shows: `unknown` when the API did not say. */
export type RowState = 'ok' | 'down' | 'unknown';

export type HealthReport = {
  api: RowState;
  database: RowState;
  redis: RowState;
  /** The database's clock, as the API gave it; null when it gave none. */
  time: string | null;
};

const DOWN: HealthReport = { api: 'down', database: 'down', redis: 'down', time: null };

/** The API answered "too many requests": it is up, but it said nothing about the rest. */
const BUSY: HealthReport = { api: 'ok', database: 'unknown', redis: 'unknown', time: null };

type Options = {
  /** Address of the visitor, so the API's rate limit counts visitors and not our server. */
  forwardedFor?: string | null;
  fetch?: typeof fetch;
  timeoutMs?: number;
};

/**
 * GET /api/v1/health. Never throws.
 * - 200: the state of each service;
 * - 429: the API is up (it answered), the rest is unknown;
 * - anything else (503 because a service it needs is down, no answer, a timeout): down.
 */
export async function fetchHealth(options: Options = {}): Promise<HealthReport> {
  try {
    const client = createApiClient(options.fetch ? { fetch: options.fetch } : {});

    const { data, response } = await client.GET('/v1/health', {
      headers: options.forwardedFor ? { 'X-Forwarded-For': options.forwardedFor } : {},
      cache: 'no-store',
      signal: AbortSignal.timeout(options.timeoutMs ?? 4000),
    });

    if (response.status === 429) return BUSY;
    if (!data || data.data.status !== 'ok') return DOWN;

    return {
      api: 'ok',
      database: data.data.checks.database === 'ok' ? 'ok' : 'down',
      redis: data.data.checks.redis === 'ok' ? 'ok' : 'down',
      time: data.data.time,
    };
  } catch {
    return DOWN;
  }
}

const CACHE_MS = 2000;

const recent = new Map<string, { at: number; report: Promise<HealthReport> }>();

/**
 * The same, remembered for two seconds per visitor address: a page reloaded, or several
 * pages opened at once, ask the API once. Without it a busy visitor, or a test run, uses
 * up the API's 60 requests a minute on a card that cannot change that fast.
 */
export function fetchHealthCached(
  options: Options & { now?: () => number } = {},
): Promise<HealthReport> {
  const now = (options.now ?? Date.now)();
  const key = options.forwardedFor ?? '';

  for (const [address, entry] of recent) {
    if (now - entry.at >= CACHE_MS) recent.delete(address);
  }

  const known = recent.get(key);
  if (known) return known.report;

  const report = fetchHealth(options);
  recent.set(key, { at: now, report });

  return report;
}

/** For the tests: forget what was remembered. */
export function forgetCachedHealth(): void {
  recent.clear();
}
