/*
 * The rules of the party form (docs/api/parties/POST-elections-{election}-parties.md), checked
 * before the call so an obvious mistake does not wait for the network. The codes are the API's
 * own (`required`, `max`), so one message table serves both.
 *
 * A colour is kept as six upper-case hexadecimal digits, without the `#`: that is how the radio
 * inputs are named (`party-form-colour-5468D4`). The API's `#RRGGBB` is made in `bodyOf`.
 */

import type { Party } from '@/lib/api/parties';

export const NAME_MAX = 100;
export const ACRONYM_MAX = 15;

/**
 * The eight colours of the form, in order (the first is the default). A party's own colour is
 * data and may be any `#RRGGBB`; these are the choices offered. Each one reaches AA contrast
 * with white text (the swatch of the card writes in white).
 */
export const PARTY_COLOURS = [
  '5468D4',
  'C2410C',
  '0F766E',
  '7C5BD0',
  'A8349A',
  '8A5A00',
  '1E2A5A',
  '9A2A0A',
] as const;

export type PartyDraft = {
  name: string;
  acronym: string;
  /** Six hexadecimal digits, upper case, no `#`. */
  colour: string;
};

export type PartyFieldErrors = Partial<Record<'name' | 'acronym' | 'colour', string>>;

export const EMPTY_DRAFT: PartyDraft = { name: '', acronym: '', colour: PARTY_COLOURS[0] };

/** The six digits in upper case, with or without the `#` in front. */
export function colourDigits(colour: string): string {
  return colour.replace(/^#/, '').toUpperCase();
}

/** The six digits with the `#` in front, for the style of a swatch and the body of a call. */
export function colourValue(digits: string): string {
  return `#${colourDigits(digits)}`;
}

/** The draft that fills the form to change this party. */
export function draftOf(party: Pick<Party, 'name' | 'acronym' | 'colour'>): PartyDraft {
  return {
    name: party.name,
    acronym: party.acronym ?? '',
    colour: colourDigits(party.colour),
  };
}

/** The field → code of every rule that fails; an empty object when the draft is valid. */
export function validateParty(draft: PartyDraft): PartyFieldErrors {
  const errors: PartyFieldErrors = {};
  const name = draft.name.trim();

  if (name === '') errors.name = 'required';
  else if (Array.from(name).length > NAME_MAX) errors.name = 'max';

  if (Array.from(draft.acronym.trim()).length > ACRONYM_MAX) errors.acronym = 'max';

  if (!/^[0-9A-F]{6}$/.test(colourDigits(draft.colour))) errors.colour = 'hex_colour';

  return errors;
}

/** The body of the create and update calls; a blank acronym is sent as `null`. */
export function bodyOf(draft: PartyDraft): {
  name: string;
  acronym: string | null;
  colour: string;
} {
  const acronym = draft.acronym.trim();

  return {
    name: draft.name.trim(),
    acronym: acronym === '' ? null : acronym,
    colour: colourValue(draft.colour),
  };
}

/** The first code the API gave for each field of the form (other fields are not ours). */
export function apiFieldErrors(fields: Record<string, string[]>): PartyFieldErrors {
  const errors: PartyFieldErrors = {};

  for (const name of ['name', 'acronym', 'colour'] as const) {
    const code = fields[name]?.[0];

    if (code) errors[name] = code;
  }

  return errors;
}

/** What the swatch of a party writes: the acronym, else the first two letters of the name. */
export function swatchText(party: Pick<Party, 'name' | 'acronym'>): string {
  const acronym = party.acronym?.trim() ?? '';

  if (acronym !== '') return acronym;

  return Array.from(party.name.trim()).slice(0, 2).join('').toLocaleUpperCase();
}
