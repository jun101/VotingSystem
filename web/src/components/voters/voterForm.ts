/*
 * The rules of the voter form (docs/api/voters/POST-elections-{election}-voters.md), checked
 * before the call so an obvious mistake does not wait for the network. The codes are the API's
 * own (`required`, `max`, `invalid`), so one message table serves both.
 */

import type { Voter } from '@/lib/api/voters';

export const NAME_MAX = 150;
export const GROUP_MAX = 100;
export const IDENTIFIER_MAX = 50;
export const EMAIL_MAX = 255;
export const PHONE_MAX = 30;

export type VoterDraft = {
  full_name: string;
  group: string;
  identifier: string;
  email: string;
  phone: string;
};

export type VoterField = keyof VoterDraft;

export type VoterFieldErrors = Partial<Record<VoterField, string>>;

export const FIELDS: readonly VoterField[] = ['full_name', 'group', 'identifier', 'email', 'phone'];

export const EMPTY_DRAFT: VoterDraft = {
  full_name: '',
  group: '',
  identifier: '',
  email: '',
  phone: '',
};

/** The draft that fills the form to change this voter. */
export function draftOf(
  voter: Pick<Voter, 'full_name' | 'group' | 'identifier' | 'email' | 'phone'>,
): VoterDraft {
  return {
    full_name: voter.full_name,
    group: voter.group?.name ?? '',
    identifier: voter.identifier ?? '',
    email: voter.email ?? '',
    phone: voter.phone ?? '',
  };
}

/** The draft after "save and add another": the group is kept, everything else is cleared. */
export function nextDraft(draft: VoterDraft): VoterDraft {
  return { ...EMPTY_DRAFT, group: draft.group };
}

const length = (text: string) => Array.from(text.trim()).length;

/** The field → code of every rule that fails; an empty object when the draft is valid. */
export function validateVoter(draft: VoterDraft): VoterFieldErrors {
  const errors: VoterFieldErrors = {};

  if (length(draft.full_name) === 0) errors.full_name = 'required';
  else if (length(draft.full_name) > NAME_MAX) errors.full_name = 'max';

  if (length(draft.group) > GROUP_MAX) errors.group = 'max';
  if (length(draft.identifier) > IDENTIFIER_MAX) errors.identifier = 'max';
  if (length(draft.email) > EMAIL_MAX) errors.email = 'max';

  const phone = draft.phone.trim();

  if (length(phone) > PHONE_MAX) errors.phone = 'max';
  else if (!/^[0-9+\-().\s]*$/.test(phone)) errors.phone = 'invalid';

  return errors;
}

/** The body of the create and update calls; a blank optional field is sent as `null`. */
export function bodyOf(draft: VoterDraft): {
  full_name: string;
  group: string | null;
  identifier: string | null;
  email: string | null;
  phone: string | null;
} {
  const orNull = (text: string) => (text.trim() === '' ? null : text.trim());

  return {
    full_name: draft.full_name.trim(),
    group: orNull(draft.group),
    identifier: orNull(draft.identifier),
    email: orNull(draft.email),
    phone: orNull(draft.phone),
  };
}

/** The first code the API gave for each field of the form (other fields are not ours). */
export function apiFieldErrors(fields: Record<string, string[]>): VoterFieldErrors {
  const errors: VoterFieldErrors = {};

  for (const name of FIELDS) {
    const code = fields[name]?.[0];

    if (code) errors[name] = code;
  }

  return errors;
}
