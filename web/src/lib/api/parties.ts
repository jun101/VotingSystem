import type { components, operations } from './schema';

/** One party (a slate, a list): an item of `GET /elections/{election}/parties`. */
export type Party = Omit<
  operations['party.index']['responses'][200]['content']['application/json']['data'][number],
  'logo'
> & {
  /** `null` until slice 06d (the generated client leaves a key that is always null out). */
  logo?: null;
};

/** The body of `POST /elections/{election}/parties`. */
export type NewParty = components['schemas']['CreatePartyRequest'];

/** The body of `PATCH /parties/{party}`: every field optional. */
export type PartyChanges = components['schemas']['UpdatePartyRequest'];

/** An election holds at most 30 parties (`party_limit_reached` otherwise). */
export const PARTY_LIMIT = 30;
