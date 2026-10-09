import { fromLocalInput, isSupportedZone, toLocalInput } from '@/lib/format/zonedTime';

type Original = { starts_at: string; ends_at: string; timezone: string };
type Typed = { starts: string; ends: string; timezone: string };

const iso = (date: Date) => date.toISOString().replace('.000Z', 'Z');

/**
 * The date fields of the request.
 *
 * - A new election always carries both; one that is empty or half typed is sent empty (the API
 *   says it is required).
 * - An election being edited carries a date only when its local text, or the time zone, differs
 *   from what the stored instant shows. An unchanged one is left out, so the stored instant (its
 *   seconds, or the second occurrence of a repeated hour) is not moved by a round trip.
 * - In a zone this browser cannot compute, no date is sent at all.
 */
export function dateFields(
  typed: Typed,
  original?: Original,
): { starts_at?: string; ends_at?: string } {
  if (!isSupportedZone(typed.timezone)) return {};

  const zoneMoved = original !== undefined && typed.timezone !== original.timezone;
  const field = (text: string, stored: string | undefined): string | undefined => {
    if (original !== undefined && stored !== undefined) {
      const shown = toLocalInput(stored, original.timezone);

      if (!zoneMoved && text === shown) return undefined;
    }

    const read = fromLocalInput(text, typed.timezone);

    return read ? iso(read.utc) : '';
  };

  const result: { starts_at?: string; ends_at?: string } = {};
  const starts = field(typed.starts, original?.starts_at);
  const ends = field(typed.ends, original?.ends_at);

  if (starts !== undefined) result.starts_at = starts;
  if (ends !== undefined) result.ends_at = ends;

  return result;
}
