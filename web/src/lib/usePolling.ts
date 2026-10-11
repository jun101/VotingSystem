'use client';

import { useEffect, useRef } from 'react';

/** The pages that follow a live election read again this often. */
export const POLL_MS = 15_000;

/**
 * Calls `callback` every `interval` ms while `active` is true. Visibility-aware: nothing is
 * called while the tab is hidden, and when it comes back a read that was due is made at once.
 * The timer is cleared when `active` turns false and when the component unmounts. The
 * callback is read from a ref, so a new function each render does not restart the timer.
 */
export function usePolling(
  callback: () => void,
  active: boolean,
  interval: number = POLL_MS,
): void {
  const latest = useRef(callback);

  useEffect(() => {
    latest.current = callback;
  });

  useEffect(() => {
    if (!active) return;

    let last = Date.now();
    let timer = 0;

    const run = () => {
      last = Date.now();
      latest.current();
    };

    const start = () => {
      window.clearInterval(timer);
      timer = window.setInterval(() => {
        if (document.visibilityState === 'hidden') return;

        run();
      }, interval);
    };

    // Back on the tab after a read was due: one read now, and the interval counts again from here,
    // so a second read does not follow within seconds.
    const onVisible = () => {
      if (document.visibilityState === 'visible' && Date.now() - last >= interval) {
        run();
        start();
      }
    };

    start();
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, [active, interval]);
}
