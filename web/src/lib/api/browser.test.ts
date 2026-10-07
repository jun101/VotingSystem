import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('./client', () => ({ apiBaseUrl: () => 'http://localhost/api' }));

type Call = { url: string; method: string; headers: Headers; body: string };

let calls: Call[];
let answers: ((call: Call) => Response)[];

function clearCookie(name: string) {
  document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
}

function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

beforeEach(() => {
  vi.resetModules();
  calls = [];
  answers = [];
  clearCookie('XSRF-TOKEN');
  document.documentElement.lang = 'en';

  vi.stubGlobal('fetch', async (input: Request | string | URL, init?: RequestInit) => {
    const request = input instanceof Request ? input : new Request(input, init);
    const call = {
      url: request.url,
      method: request.method,
      headers: request.headers,
      body: await request.text(),
    };
    calls.push(call);

    const answer = answers.shift();
    if (!answer) throw new Error(`no answer planned for ${request.method} ${request.url}`);

    return answer(call);
  });
});

afterEach(() => vi.unstubAllGlobals());

const csrfAnswer = () => {
  document.cookie = 'XSRF-TOKEN=token%20one; path=/';

  return new Response(null, { status: 204 });
};

describe('the browser client', () => {
  it('fetches the CSRF cookie once, then sends it with the language on what changes something', async () => {
    const { logout, resendVerification } = await import('./browser');
    answers = [
      csrfAnswer,
      () => new Response(null, { status: 204 }),
      () => new Response(null, { status: 204 }),
    ];

    await logout();
    await resendVerification();

    expect(calls.map((c) => `${c.method} ${new URL(c.url).pathname}`)).toEqual([
      'GET /api/v1/auth/csrf',
      'POST /api/v1/auth/logout',
      'POST /api/v1/auth/verify-email/resend',
    ]);
    expect(calls[1]?.headers.get('X-XSRF-TOKEN')).toBe('token one');
    expect(calls[2]?.headers.get('X-XSRF-TOKEN')).toBe('token one');
    expect(calls[1]?.headers.get('Accept-Language')).toBe('en');
  });

  it('does not send the CSRF token on a read', async () => {
    const { login } = await import('./browser');
    document.cookie = 'XSRF-TOKEN=abc; path=/';
    answers = [() => json(200, { data: { id: 'x' } })];

    const user = await login({ email: 'a@b.test', password: 'p' });

    expect(user).toEqual({ id: 'x' });
    expect(calls).toHaveLength(1);
    expect(JSON.parse(calls[0]!.body)).toEqual({ email: 'a@b.test', password: 'p' });
  });

  it('fetches a new token and repeats the call once when the answer is 419', async () => {
    const { logout } = await import('./browser');
    document.cookie = 'XSRF-TOKEN=stale; path=/';
    answers = [
      () => json(419, { error: { code: 'csrf_mismatch', message: 'x' } }),
      csrfAnswer,
      () => new Response(null, { status: 204 }),
    ];

    await logout();

    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'POST']);
    expect(calls[2]?.headers.get('X-XSRF-TOKEN')).toBe('token one');
  });

  it('gives up after one repeat', async () => {
    const { logout } = await import('./browser');
    document.cookie = 'XSRF-TOKEN=stale; path=/';
    const refused = () => json(419, { error: { code: 'csrf_mismatch', message: 'x' } });
    answers = [refused, csrfAnswer, refused];

    await expect(logout()).rejects.toMatchObject({ status: 419, code: 'csrf_mismatch' });
  });

  it('turns an error answer into an ApiError with its fields and its retry delay', async () => {
    const { register } = await import('./browser');
    document.cookie = 'XSRF-TOKEN=abc; path=/';
    answers = [
      () =>
        json(
          422,
          { error: { code: 'validation_failed', message: 'x', fields: { email: ['taken'] } } },
          { 'Retry-After': '7' },
        ),
    ];

    await expect(
      register({
        institution_name: 'a',
        name: 'b',
        email: 'c@d.test',
        password: 'p',
        language: 'fr',
      }),
    ).rejects.toMatchObject({
      status: 422,
      code: 'validation_failed',
      fields: { email: ['taken'] },
      retryAfter: 7,
    });
  });

  it('turns a rate-limited CSRF call into the error the forms show, and sends nothing else', async () => {
    const { logout } = await import('./browser');
    answers = [
      () =>
        json(429, { error: { code: 'too_many_attempts', message: 'x' } }, { 'Retry-After': '12' }),
    ];

    await expect(logout()).rejects.toMatchObject({
      status: 429,
      code: 'too_many_attempts',
      retryAfter: 12,
    });
    expect(calls.map((c) => c.method)).toEqual(['GET']);
  });

  it('turns a server error or a network failure of the CSRF call into an error too', async () => {
    const { logout } = await import('./browser');
    answers = [() => new Response('<html>', { status: 502 })];

    await expect(logout()).rejects.toMatchObject({ status: 502, code: 'unknown' });

    answers = [
      () => {
        throw new TypeError('Failed to fetch');
      },
    ];

    await expect(logout()).rejects.toMatchObject({ status: 0, code: 'network' });
    expect(calls.map((c) => c.method)).toEqual(['GET', 'GET']);
  });

  it('turns a failure to reach the server into a network error', async () => {
    const { forgotPassword } = await import('./browser');
    document.cookie = 'XSRF-TOKEN=abc; path=/';
    answers = [
      () => {
        throw new TypeError('Failed to fetch');
      },
    ];

    await expect(forgotPassword('a@b.test')).rejects.toMatchObject({ status: 0, code: 'network' });
  });

  it('keeps nothing in the browser storage', async () => {
    const { logout } = await import('./browser');
    answers = [csrfAnswer, () => new Response(null, { status: 204 })];

    await logout();

    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });
});
