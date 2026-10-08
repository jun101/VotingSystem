import createClient from 'openapi-fetch';
import { apiBaseUrl } from './client';
import { ApiError, parseApiError } from './errors';
import { XSRF_COOKIE } from './session';
import type { paths } from './schema';
import type { CurrentUser, RegisterBody } from './user';

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

export async function login(body: { email: string; password: string }): Promise<CurrentUser> {
  const { data } = await send((api) => api.POST('/v1/auth/login', { body }));

  return data!.data;
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
