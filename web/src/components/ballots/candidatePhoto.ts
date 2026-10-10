/*
 * The photo of a candidate in the form: the check of a chosen file (done before sending, the API
 * checks again) and the sequence of a save. The sequence takes the API calls as a parameter so it
 * can be tried without a network. The same rules and the same shape as the party logo.
 */

import { ApiError } from '@/lib/api/errors';
import type { Candidate, CandidateChanges, NewCandidate } from '@/lib/api/candidates';
import { LOGO_MAX_BYTES, logoFileProblem } from './partyLogo';

export const PHOTO_MAX_BYTES = LOGO_MAX_BYTES;

/**
 * The error code of a file that cannot be a photo, or `null`: over 5 MB, or a type other than
 * JPEG, PNG and WebP. A file whose type the system does not tell goes to the API.
 */
export function photoFileProblem(file: Pick<File, 'size' | 'type'>): string | null {
  return logoFileProblem(file);
}

/** What the person did to the photo in the form. */
export type PhotoChange = { kind: 'keep' } | { kind: 'set'; file: File } | { kind: 'remove' };

export type CandidateSaveApi = {
  create: (ballot: string, body: NewCandidate) => Promise<Candidate>;
  update: (id: string, body: CandidateChanges) => Promise<Candidate>;
  upload: (id: string, file: File) => Promise<Candidate>;
  remove: (id: string) => Promise<void>;
};

export type CandidateSaveResult = {
  /** The candidate as it stands after the steps that worked. */
  candidate: Candidate;
  /** The error of the photo step, when the fields were saved and the photo was not. */
  photoError: ApiError | null;
};

/**
 * Saves the fields (a creation, or a change when `target.candidate` is given), then the photo if
 * it changed. A failure of the fields throws (nothing else was tried). A failure of the photo
 * step does not: the candidate is saved, and the error comes back beside it so the form can stay
 * open on it. A removal of a photo the candidate does not have is not sent.
 */
export async function saveCandidateWithPhoto(
  api: CandidateSaveApi,
  target: { ballot: string; candidate: Candidate | null },
  body: NewCandidate,
  changes: CandidateChanges,
  photo: PhotoChange,
): Promise<CandidateSaveResult> {
  let saved = target.candidate
    ? await api.update(target.candidate.id, changes)
    : await api.create(target.ballot, body);

  try {
    if (photo.kind === 'set') {
      saved = await api.upload(saved.id, photo.file);
    } else if (photo.kind === 'remove' && saved.photo) {
      await api.remove(saved.id);
      saved = { ...saved, photo: null };
    }
  } catch (caught) {
    return {
      candidate: saved,
      photoError: caught instanceof ApiError ? caught : new ApiError(0, 'unknown'),
    };
  }

  return { candidate: saved, photoError: null };
}
