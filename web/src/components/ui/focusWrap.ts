/** What a modal can hold that takes the keyboard focus. */
export const FOCUSABLE =
  'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])';

/**
 * Where the focus goes on a Tab press inside a modal, so it never leaves it. `items` are the
 * focusable elements in order; `inside` says whether `active` is one of the modal's own
 * descendants. Returns `null` when the browser's own move is right, else the element to focus
 * (the press is then cancelled). With nothing focusable, `undefined`: cancel and focus nothing.
 */
export function wrapTarget<T>(
  items: readonly T[],
  active: T | null,
  shift: boolean,
  inside: boolean,
): T | null | undefined {
  const first = items[0];
  const last = items[items.length - 1];

  if (first === undefined || last === undefined) return undefined;

  if (shift && (active === first || !inside)) return last;
  if (!shift && (active === last || !inside)) return first;

  return null;
}
