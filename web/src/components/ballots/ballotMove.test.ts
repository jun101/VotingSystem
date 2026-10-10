import { describe, expect, it } from 'vitest';
import { dropOn, moveBy, moveItem, orderOf, sameOrder } from './ballotMove';

const list = ['a', 'b', 'c', 'd'].map((id) => ({ id }));
const ids = (items: { id: string }[]) => items.map((item) => item.id).join('');

describe('moveItem', () => {
  it('takes an item out and puts it at the new place', () => {
    expect(moveItem([1, 2, 3, 4], 0, 2)).toEqual([2, 3, 1, 4]);
    expect(moveItem([1, 2, 3, 4], 3, 0)).toEqual([4, 1, 2, 3]);
  });

  it('returns a copy, unchanged, for the same place or a place outside the list', () => {
    const items = [1, 2, 3];

    expect(moveItem(items, 1, 1)).toEqual(items);
    expect(moveItem(items, 1, 1)).not.toBe(items);
    expect(moveItem(items, 0, 5)).toEqual(items);
    expect(moveItem(items, -1, 1)).toEqual(items);
  });

  it('does not change the list it is given', () => {
    const items = [1, 2, 3];

    moveItem(items, 0, 2);

    expect(items).toEqual([1, 2, 3]);
  });
});

describe('moveBy', () => {
  it('moves one place up or down', () => {
    expect(ids(moveBy(list, 'b', -1))).toBe('bacd');
    expect(ids(moveBy(list, 'b', 1))).toBe('acbd');
  });

  it('leaves the first item that goes up and the last that goes down where they are', () => {
    expect(ids(moveBy(list, 'a', -1))).toBe('abcd');
    expect(ids(moveBy(list, 'd', 1))).toBe('abcd');
  });

  it('ignores an id that is not in the list', () => {
    expect(ids(moveBy(list, 'z', 1))).toBe('abcd');
  });
});

describe('dropOn', () => {
  it('puts the dragged item in the place of the one it is dropped on', () => {
    expect(ids(dropOn(list, 'd', 'a'))).toBe('dabc');
    expect(ids(dropOn(list, 'a', 'c'))).toBe('bcad');
  });

  it('does nothing when dropped on itself or on something unknown', () => {
    expect(ids(dropOn(list, 'b', 'b'))).toBe('abcd');
    expect(ids(dropOn(list, 'b', 'z'))).toBe('abcd');
  });
});

describe('orderOf and sameOrder', () => {
  it('lists the ids in order, the body of the reorder call', () => {
    expect(orderOf(moveBy(list, 'c', -1))).toEqual(['a', 'c', 'b', 'd']);
  });

  it('tells whether two lists are in the same order', () => {
    expect(sameOrder(list, [...list])).toBe(true);
    expect(sameOrder(list, moveBy(list, 'a', 1))).toBe(false);
    expect(sameOrder(list, list.slice(0, 3))).toBe(false);
  });
});
