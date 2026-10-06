import createClient from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Where the API is reached. In the browser: the same origin, through the proxy. On the
 * server: the API container, through the internal network.
 */
export function apiBaseUrl(): string {
  if (typeof window !== 'undefined') return '/api';

  return `${process.env.API_INTERNAL_URL ?? 'http://api:8000'}/api`;
}

/** The typed client. Paths and shapes come from the generated schema, none by hand. */
export function createApiClient(options: { fetch?: typeof fetch } = {}) {
  return createClient<paths>({
    baseUrl: apiBaseUrl(),
    ...(options.fetch ? { fetch: options.fetch } : {}),
  });
}
