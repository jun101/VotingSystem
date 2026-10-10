/*
 * The logo of a party in the form: the check of a chosen file (done before sending, the API
 * checks again) and the sequence of a save. The sequence takes the API calls as a parameter so
 * it can be tried without a network.
 */

import { ApiError } from '@/lib/api/errors';
import type { NewParty, Party, PartyChanges } from '@/lib/api/parties';

export const LOGO_MAX_BYTES = 5 * 1024 * 1024;

const LOGO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * The error code of a file that cannot be a logo, or `null`. A file whose type the system does
 * not tell (an empty one) goes to the API, which judges the content.
 */
export function logoFileProblem(file: Pick<File, 'size' | 'type'>): string | null {
  if (file.size > LOGO_MAX_BYTES) return 'file_too_large';

  if (file.type !== '' && !LOGO_TYPES.includes(file.type)) return 'file_type_not_allowed';

  return null;
}

/** What the person did to the logo in the form. */
export type LogoChange = { kind: 'keep' } | { kind: 'set'; file: File } | { kind: 'remove' };

export type PartySaveApi = {
  create: (election: string, body: NewParty) => Promise<Party>;
  update: (id: string, body: PartyChanges) => Promise<Party>;
  upload: (id: string, file: File) => Promise<Party>;
  remove: (id: string) => Promise<void>;
};

export type PartySaveResult = {
  /** The party as it stands after the steps that worked. */
  party: Party;
  /** The error of the logo step, when the fields were saved and the logo was not. */
  logoError: ApiError | null;
};

/**
 * Saves the fields, then the logo if it changed. A failure of the fields throws (nothing else
 * was tried). A failure of the logo step does not: the party is saved, and the error comes back
 * beside it so the form can stay open on it. A removal of a logo the party does not have is not
 * sent.
 */
export async function savePartyWithLogo(
  api: PartySaveApi,
  target: { election: string; party: Party | null },
  body: NewParty,
  logo: LogoChange,
): Promise<PartySaveResult> {
  let saved = target.party
    ? await api.update(target.party.id, body)
    : await api.create(target.election, body);

  try {
    if (logo.kind === 'set') {
      saved = await api.upload(saved.id, logo.file);
    } else if (logo.kind === 'remove' && saved.logo) {
      await api.remove(saved.id);
      saved = { ...saved, logo: null };
    }
  } catch (caught) {
    return {
      party: saved,
      logoError: caught instanceof ApiError ? caught : new ApiError(0, 'unknown'),
    };
  }

  return { party: saved, logoError: null };
}
