// @vitest-environment node
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchHealth, fetchHealthCached, forgetCachedHealth } from './health';

function answer(body: unknown, status = 200): typeof fetch {
  return vi.fn(async () => Response.json(body, { status })) as unknown as typeof fetch;
}

const down = { api: 'down', database: 'down', redis: 'down', time: null };

const ok = {
  data: { status: 'ok', checks: { database: 'ok', redis: 'ok' }, time: '2026-10-06T14:03:27Z' },
};

describe('fetchHealth', () => {
  it('reports the services and the time of a healthy API', async () => {
    const report = await fetchHealth({ fetch: answer(ok) });

    expect(report).toEqual({
      api: 'ok',
      database: 'ok',
      redis: 'ok',
      time: '2026-10-06T14:03:27Z',
    });
  });

  it('calls the health path of the API', async () => {
    const fetcher = answer(ok);

    await fetchHealth({ fetch: fetcher });

    const request = vi.mocked(fetcher).mock.calls[0]![0] as Request;
    expect(new URL(request.url).pathname).toBe('/api/v1/health');
  });

  it('forwards the address of the visitor', async () => {
    const fetcher = answer(ok);

    await fetchHealth({ fetch: fetcher, forwardedFor: '203.0.113.9' });

    const request = vi.mocked(fetcher).mock.calls[0]![0] as Request;
    expect(request.headers.get('x-forwarded-for')).toBe('203.0.113.9');
  });

  it('reports a service that is down when the API says so', async () => {
    const report = await fetchHealth({
      fetch: answer({ data: { ...ok.data, checks: { database: 'ok', redis: 'down' } } }),
    });

    expect(report).toMatchObject({ api: 'ok', database: 'ok', redis: 'down' });
  });

  it('reports everything down when the API answers 503', async () => {
    const report = await fetchHealth({
      fetch: answer({ error: { code: 'dependency_unavailable' } }, 503),
    });

    expect(report).toEqual(down);
  });

  it('reports the API up and the rest unknown when the API answers 429', async () => {
    const report = await fetchHealth({
      fetch: answer({ error: { code: 'too_many_attempts' } }, 429),
    });

    expect(report).toEqual({ api: 'ok', database: 'unknown', redis: 'unknown', time: null });
  });

  it('reports everything down when the request fails', async () => {
    const failing = vi.fn(async () => {
      throw new TypeError('fetch failed');
    }) as unknown as typeof fetch;

    expect(await fetchHealth({ fetch: failing })).toEqual(down);
  });

  it('reports everything down when the API does not answer in time', async () => {
    const slow = vi.fn(
      (input: Request) =>
        new Promise<Response>((_, reject) => {
          input.signal.addEventListener('abort', () => reject(new DOMException('x', 'AbortError')));
        }),
    ) as unknown as typeof fetch;

    expect(await fetchHealth({ fetch: slow, timeoutMs: 20 })).toEqual(down);
  });
});

describe('fetchHealthCached', () => {
  afterEach(() => forgetCachedHealth());

  it('asks the API once for the same visitor within two seconds', async () => {
    const fetcher = answer(ok);

    await fetchHealthCached({ fetch: fetcher, forwardedFor: '203.0.113.9', now: () => 1000 });
    await fetchHealthCached({ fetch: fetcher, forwardedFor: '203.0.113.9', now: () => 2999 });

    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('asks again after two seconds', async () => {
    const fetcher = answer(ok);

    await fetchHealthCached({ fetch: fetcher, now: () => 1000 });
    await fetchHealthCached({ fetch: fetcher, now: () => 3000 });

    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('keeps one answer per visitor address', async () => {
    const fetcher = answer(ok);

    await fetchHealthCached({ fetch: fetcher, forwardedFor: '203.0.113.9', now: () => 1000 });
    await fetchHealthCached({ fetch: fetcher, forwardedFor: '203.0.113.10', now: () => 1000 });

    expect(fetcher).toHaveBeenCalledTimes(2);
  });
});
