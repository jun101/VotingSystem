// @vitest-environment node
import { describe, expect, it, vi } from 'vitest';
import { fetchHealth } from './health';

function answer(body: unknown, status = 200): typeof fetch {
  return vi.fn(async () => Response.json(body, { status })) as unknown as typeof fetch;
}

const ok = {
  data: { status: 'ok', checks: { database: 'ok', redis: 'ok' }, time: '2026-10-06T14:03:27Z' },
};

describe('fetchHealth', () => {
  it('reports the services and the time of a healthy API', async () => {
    const report = await fetchHealth({ fetch: answer(ok) });

    expect(report).toEqual({
      reachable: true,
      database: true,
      redis: true,
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

  it('reports not reachable when the API answers 503', async () => {
    const report = await fetchHealth({
      fetch: answer({ error: { code: 'dependency_unavailable' } }, 503),
    });

    expect(report).toEqual({ reachable: false });
  });

  it('reports not reachable when the request fails', async () => {
    const failing = vi.fn(async () => {
      throw new TypeError('fetch failed');
    }) as unknown as typeof fetch;

    expect(await fetchHealth({ fetch: failing })).toEqual({ reachable: false });
  });

  it('reports not reachable when the API does not answer in time', async () => {
    const slow = vi.fn(
      (input: Request) =>
        new Promise<Response>((_, reject) => {
          input.signal.addEventListener('abort', () => reject(new DOMException('x', 'AbortError')));
        }),
    ) as unknown as typeof fetch;

    expect(await fetchHealth({ fetch: slow, timeoutMs: 20 })).toEqual({ reachable: false });
  });
});
