import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { usePolling } from './usePolling';

function visibility(state: 'visible' | 'hidden') {
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: state });
  document.dispatchEvent(new Event('visibilitychange'));
}

beforeEach(() => {
  vi.useFakeTimers();
  visibility('visible');
});

afterEach(() => {
  vi.useRealTimers();
});

describe('usePolling', () => {
  it('calls the callback every interval while active', () => {
    const callback = vi.fn();

    renderHook(() => usePolling(callback, true, 1000));
    expect(callback).not.toHaveBeenCalled();

    act(() => {
      vi.advanceTimersByTime(3000);
    });

    expect(callback).toHaveBeenCalledTimes(3);
  });

  it('does nothing when not active', () => {
    const callback = vi.fn();

    renderHook(() => usePolling(callback, false, 1000));
    act(() => {
      vi.advanceTimersByTime(5000);
    });

    expect(callback).not.toHaveBeenCalled();
  });

  it('skips the ticks of a hidden tab and reads at once when it comes back', () => {
    const callback = vi.fn();

    renderHook(() => usePolling(callback, true, 1000));
    act(() => visibility('hidden'));
    act(() => {
      vi.advanceTimersByTime(5000);
    });
    expect(callback).not.toHaveBeenCalled();

    act(() => visibility('visible'));
    expect(callback).toHaveBeenCalledTimes(1);
  });

  it('stops on unmount and when it turns inactive', () => {
    const callback = vi.fn();
    const { rerender, unmount } = renderHook(({ on }) => usePolling(callback, on, 1000), {
      initialProps: { on: true },
    });

    rerender({ on: false });
    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(callback).not.toHaveBeenCalled();

    rerender({ on: true });
    unmount();
    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(callback).not.toHaveBeenCalled();
  });

  it('uses the newest callback without restarting the timer', () => {
    const first = vi.fn();
    const second = vi.fn();
    const { rerender } = renderHook(({ fn }) => usePolling(fn, true, 1000), {
      initialProps: { fn: first },
    });

    rerender({ fn: second });
    act(() => {
      vi.advanceTimersByTime(1000);
    });

    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
  });
});
