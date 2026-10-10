import { describe, expect, it } from 'vitest';
import { wrapTarget } from './focusWrap';

const items = ['close', 'name', 'save'];

describe('wrapTarget', () => {
  it('lets the browser move inside the modal', () => {
    expect(wrapTarget(items, 'name', false, true)).toBeNull();
    expect(wrapTarget(items, 'name', true, true)).toBeNull();
    expect(wrapTarget(items, 'close', false, true)).toBeNull();
    expect(wrapTarget(items, 'save', true, true)).toBeNull();
  });

  it('goes from the last control to the first with Tab', () => {
    expect(wrapTarget(items, 'save', false, true)).toBe('close');
  });

  it('goes from the first control to the last with Shift+Tab', () => {
    expect(wrapTarget(items, 'close', true, true)).toBe('save');
  });

  it('brings back a focus that is outside the modal', () => {
    expect(wrapTarget(items, null, false, false)).toBe('close');
    expect(wrapTarget(items, null, true, false)).toBe('save');
  });

  it('cancels the press when nothing can take the focus', () => {
    expect(wrapTarget([], null, false, false)).toBeUndefined();
  });
});
