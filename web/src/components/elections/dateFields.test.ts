import { describe, expect, it } from 'vitest';
import { dateFields } from './dateFields';

const paris = (starts_at: string, ends_at: string) => ({
  starts_at,
  ends_at,
  timezone: 'Europe/Paris',
});

describe('dateFields', () => {
  it('sends both dates of a new election, empty when not typed', () => {
    expect(
      dateFields({ starts: '2026-10-12T08:00', ends: '', timezone: 'America/Port-au-Prince' }),
    ).toEqual({ starts_at: '2026-10-12T12:00:00Z', ends_at: '' });
  });

  it('sends nothing for an edit that leaves the dates as they show', () => {
    // 10:00 local with seconds stored: 08:00:30Z shows as 10:00 in Paris in summer.
    const original = paris('2026-07-01T08:00:30Z', '2026-07-02T08:00:00Z');

    expect(
      dateFields(
        { starts: '2026-07-01T10:00', ends: '2026-07-02T10:00', timezone: 'Europe/Paris' },
        original,
      ),
    ).toEqual({});
  });

  it('does not move the second occurrence of a repeated hour', () => {
    // 01:30Z on 25 October 2026 is the second 02:30 in Paris.
    const original = paris('2026-10-25T01:30:00Z', '2026-10-26T01:30:00Z');

    expect(
      dateFields(
        { starts: '2026-10-25T02:30', ends: '2026-10-26T02:30', timezone: 'Europe/Paris' },
        original,
      ),
    ).toEqual({});
  });

  it('sends only the date whose text changed', () => {
    const original = paris('2026-07-01T08:00:30Z', '2026-07-02T08:00:00Z');

    expect(
      dateFields(
        { starts: '2026-07-01T10:00', ends: '2026-07-02T11:00', timezone: 'Europe/Paris' },
        original,
      ),
    ).toEqual({ ends_at: '2026-07-02T09:00:00Z' });
  });

  it('sends both when the time zone changed', () => {
    const original = paris('2026-07-01T08:00:00Z', '2026-07-02T08:00:00Z');

    expect(
      dateFields(
        { starts: '2026-07-01T10:00', ends: '2026-07-02T10:00', timezone: 'UTC' },
        original,
      ),
    ).toEqual({ starts_at: '2026-07-01T10:00:00Z', ends_at: '2026-07-02T10:00:00Z' });
  });

  it('sends no date in a zone the browser does not know', () => {
    expect(
      dateFields({
        starts: '2026-07-01T10:00',
        ends: '2026-07-02T10:00',
        timezone: 'Mars/Olympus',
      }),
    ).toEqual({});
    expect(
      dateFields(
        { starts: '2026-07-01T10:00', ends: '2026-07-02T10:00', timezone: 'Mars/Olympus' },
        {
          starts_at: '2026-07-01T08:00:00Z',
          ends_at: '2026-07-02T08:00:00Z',
          timezone: 'Mars/Olympus',
        },
      ),
    ).toEqual({});
  });
});
