/*
 * The rules of the ballot form (docs/api/ballots/POST-elections-{election}-ballots.md), checked
 * before the call so an obvious mistake does not wait for the network. The codes are the API's
 * own (`required`, `max`, `integer`, `min`), so one message table serves both.
 */

export const TITLE_MAX = 200;
export const DESCRIPTION_MAX = 1000;
export const SEATS_MIN = 1;
export const SEATS_MAX = 20;

export type BallotDraft = {
  /** Who the position is for; the groups only count for `group`. */
  scope: 'general' | 'group';
  /** The UUIDs of the chosen groups. */
  groups: string[];
  title: string;
  description: string;
  /** What is in the field: a number is read from it. */
  seats: string;
  allowBlank: boolean;
};

export type BallotFieldErrors = Partial<
  Record<'title' | 'description' | 'seats' | 'groups', string>
>;

export const EMPTY_DRAFT: BallotDraft = {
  scope: 'general',
  groups: [],
  title: '',
  description: '',
  seats: '1',
  allowBlank: true,
};

/** The field → code of every rule that fails; an empty object when the draft is valid. */
export function validateBallot(draft: BallotDraft): BallotFieldErrors {
  const errors: BallotFieldErrors = {};
  const title = draft.title.trim();
  const seats = draft.seats.trim();

  if (title === '') errors.title = 'required';
  else if (Array.from(title).length > TITLE_MAX) errors.title = 'max';

  if (Array.from(draft.description.trim()).length > DESCRIPTION_MAX) errors.description = 'max';

  if (!/^-?\d+$/.test(seats)) errors.seats = 'integer';
  else if (Number(seats) < SEATS_MIN) errors.seats = 'min';
  else if (Number(seats) > SEATS_MAX) errors.seats = 'max';

  if (draft.scope === 'group' && draft.groups.length === 0) errors.groups = 'required';

  return errors;
}

/** The body of the create and update calls; a blank description is sent as `null`. */
export function bodyOf(draft: BallotDraft): {
  title: string;
  description: string | null;
  seats: number;
  allow_blank: boolean;
  scope: 'general' | 'group';
  groups?: string[];
} {
  const description = draft.description.trim();

  return {
    title: draft.title.trim(),
    description: description === '' ? null : description,
    seats: Number(draft.seats.trim()),
    allow_blank: draft.allowBlank,
    scope: draft.scope,
    // The groups only go with the group scope: a general position keeps none.
    ...(draft.scope === 'group' ? { groups: draft.groups } : {}),
  };
}

/** The first code the API gave for each field of the form (other fields are not ours). */
export function apiFieldErrors(fields: Record<string, string[]>): BallotFieldErrors {
  const errors: BallotFieldErrors = {};

  for (const name of ['title', 'description', 'seats', 'groups'] as const) {
    const code = fields[name]?.[0];

    if (code) errors[name] = code;
  }

  return errors;
}
