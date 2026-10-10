/*
 * The reordering of the ballots: pure functions, so the list on the screen and the body sent to
 * `PUT /elections/{election}/ballots/order` always come from the same place.
 */

/** A copy of the list with the item at `from` taken out and put at `to` (both are 0-based). */
export function moveItem<T>(items: readonly T[], from: number, to: number): T[] {
  if (from === to || from < 0 || to < 0 || from >= items.length || to >= items.length) {
    return [...items];
  }

  const next = [...items];
  const [moved] = next.splice(from, 1);

  next.splice(to, 0, moved!);

  return next;
}

/** The list after the item with this id moves one place up (-1) or down (+1); unchanged at an end. */
export function moveBy<T extends { id: string }>(
  items: readonly T[],
  id: string,
  step: -1 | 1,
): T[] {
  const from = items.findIndex((item) => item.id === id);

  return from < 0 ? [...items] : moveItem(items, from, from + step);
}

/** The list after the item `id` is dropped on the item `onto`: it takes that item's place. */
export function dropOn<T extends { id: string }>(
  items: readonly T[],
  id: string,
  onto: string,
): T[] {
  const from = items.findIndex((item) => item.id === id);
  const to = items.findIndex((item) => item.id === onto);

  return from < 0 || to < 0 ? [...items] : moveItem(items, from, to);
}

/** The ids in order, the body of the reorder call. */
export function orderOf(items: readonly { id: string }[]): string[] {
  return items.map((item) => item.id);
}

/** True when both lists hold the same ids in the same order. */
export function sameOrder(a: readonly { id: string }[], b: readonly { id: string }[]): boolean {
  return a.length === b.length && a.every((item, index) => item.id === b[index]!.id);
}
