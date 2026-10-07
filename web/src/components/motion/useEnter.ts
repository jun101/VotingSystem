'use client';

import { useEffect, type RefObject } from 'react';

/**
 * Runs an entrance once, when the element enters the screen.
 *
 * The state lives in a `data-state` attribute that only this hook writes, after the page
 * is running: `pending` (hidden at its start position), then `in` (the final state, which
 * the stylesheet reaches with a transition). Server-rendered, and with JavaScript off or
 * with "reduce motion", the element has no state and shows its final state at once.
 * `onEnter` is called at the moment the entrance starts.
 */
export function useEnter(ref: RefObject<HTMLElement | null>, onEnter?: () => void): void {
  useEffect(() => {
    const element = ref.current;

    if (!element || typeof IntersectionObserver === 'undefined') return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let frame = 0;
    element.dataset.state = 'pending';

    const observer = new IntersectionObserver(
      (entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;

        observer.disconnect();
        // One frame later, so the start position has been drawn and the change is animated.
        frame = requestAnimationFrame(() => {
          element.dataset.state = 'in';
          onEnter?.();
        });
      },
      { rootMargin: '0px 0px -5% 0px' },
    );

    observer.observe(element);

    return () => {
      observer.disconnect();
      cancelAnimationFrame(frame);
    };
  }, [ref, onEnter]);
}
