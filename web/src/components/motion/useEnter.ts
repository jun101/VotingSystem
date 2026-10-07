'use client';

import { useEffect, useRef, type RefObject } from 'react';

/**
 * Runs an entrance once, when the element enters the screen.
 *
 * The state lives in a `data-state` attribute that only this hook writes, after the page
 * is running: `pending` (hidden at its start position, applied at once), then `in` (the
 * final state, which the stylesheet reaches with a transition declared on `in` only).
 * Server-rendered, with JavaScript off, with "reduce motion", or when the element is
 * already in view (or above it) when the page starts, the element has no state and shows
 * its final state at once: nothing visible is hidden and faded back.
 * `onEnter` is called at the moment the entrance starts; changing it does not restart
 * anything.
 */
export function useEnter(ref: RefObject<HTMLElement | null>, onEnter?: () => void): void {
  const latest = useRef(onEnter);

  useEffect(() => {
    latest.current = onEnter;
  }, [onEnter]);

  useEffect(() => {
    const element = ref.current;

    if (!element || typeof IntersectionObserver === 'undefined') return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    // Already in view, or already passed: keep it as it is. (The observer below ignores the
    // last 5 % of the screen, so an element in that band would wait for a scroll that may
    // never come: it is shown at once instead.)
    if (element.getBoundingClientRect().top < window.innerHeight) return;

    let frame = 0;
    element.dataset.state = 'pending';

    const observer = new IntersectionObserver(
      (entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;

        observer.disconnect();
        // One frame later, so the start position has been drawn and the change is animated.
        frame = requestAnimationFrame(() => {
          element.dataset.state = 'in';
          latest.current?.();
        });
      },
      { rootMargin: '0px 0px -5% 0px' },
    );

    observer.observe(element);

    return () => {
      observer.disconnect();
      cancelAnimationFrame(frame);
      // Strict mode runs the effect twice: leave no hidden state behind.
      if (element.dataset.state === 'pending') delete element.dataset.state;
    };
  }, [ref]);
}
