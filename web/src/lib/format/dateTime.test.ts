import { describe, expect, it } from 'vitest';
import { formatDateTime } from './dateTime';

describe('formatDateTime', () => {
  it('writes the time in the language of the visitor, in UTC', () => {
    expect(formatDateTime('2026-10-06T14:03:27Z', 'fr')).toContain('octobre');
    expect(formatDateTime('2026-10-06T14:03:27Z', 'en')).toContain('October');
    expect(formatDateTime('2026-10-06T14:03:27Z', 'en')).toContain('UTC');
  });

  it('shows the time to the second', () => {
    expect(formatDateTime('2026-10-06T14:03:27Z', 'fr')).toContain('14:03:27');
  });

  it('gives back what it cannot read', () => {
    expect(formatDateTime('not a date', 'fr')).toBe('not a date');
  });
});
