import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import type { Candidate } from '@/lib/api/candidates';
import {
  PHOTO_MAX_BYTES,
  photoFileProblem,
  saveCandidateWithPhoto,
  type CandidateSaveApi,
} from './candidatePhoto';

const candidate = (changes: Partial<Candidate> = {}): Candidate => ({
  id: '6e1c0a52-0000-4000-8000-000000000001',
  ballot: '6e1c0a52-0000-4000-8000-0000000000b1',
  party: null,
  first_name: 'Nadège',
  last_name: 'Pierre-Louis',
  sex: 'female',
  slogan: null,
  biography: null,
  photo: null,
  position: 1,
  created_at: '2026-10-10T10:00:00Z',
  updated_at: '2026-10-10T10:00:00Z',
  ...changes,
});

const WITH_PHOTO = { sm: '/media/a-160.webp', md: '/media/a-480.webp' };
const body = { first_name: 'Nadège', last_name: 'Pierre-Louis', sex: 'female' } as const;
const file = new File(['x'], 'photo.png', { type: 'image/png' });
const target = { ballot: 'b1', candidate: null };

function api(over: Partial<CandidateSaveApi> = {}): CandidateSaveApi {
  return {
    create: vi.fn(async () => candidate()),
    update: vi.fn(async () => candidate()),
    upload: vi.fn(async () => candidate({ photo: WITH_PHOTO })),
    remove: vi.fn(async () => undefined),
    ...over,
  };
}

describe('photoFileProblem', () => {
  it('accepts JPEG, PNG and WebP up to 5 MB', () => {
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
      expect(photoFileProblem({ size: PHOTO_MAX_BYTES, type })).toBeNull();
    }
  });

  it('refuses a file over 5 MB', () => {
    expect(photoFileProblem({ size: PHOTO_MAX_BYTES + 1, type: 'image/png' })).toBe(
      'file_too_large',
    );
  });

  it('refuses another type, and leaves an unknown type to the API', () => {
    expect(photoFileProblem({ size: 10, type: 'application/pdf' })).toBe('file_type_not_allowed');
    expect(photoFileProblem({ size: 10, type: 'image/gif' })).toBe('file_type_not_allowed');
    expect(photoFileProblem({ size: 10, type: '' })).toBeNull();
  });
});

describe('saveCandidateWithPhoto', () => {
  it('creates a candidate, then uploads its photo', async () => {
    const calls = api();
    const result = await saveCandidateWithPhoto(calls, target, body, body, { kind: 'set', file });

    expect(calls.create).toHaveBeenCalledWith('b1', body);
    expect(calls.upload).toHaveBeenCalledWith(candidate().id, file);
    expect(result.candidate.photo).toEqual(WITH_PHOTO);
    expect(result.photoError).toBeNull();
  });

  it('sends nothing more when the photo is unchanged', async () => {
    const calls = api();
    await saveCandidateWithPhoto(calls, target, body, body, { kind: 'keep' });

    expect(calls.upload).not.toHaveBeenCalled();
    expect(calls.remove).not.toHaveBeenCalled();
  });

  it('keeps the created candidate when the upload fails, and gives the error back', async () => {
    const failure = new ApiError(415, 'file_type_not_allowed');
    const calls = api({ upload: vi.fn(async () => Promise.reject(failure)) });
    const result = await saveCandidateWithPhoto(calls, target, body, body, { kind: 'set', file });

    expect(result.candidate.id).toBe(candidate().id);
    expect(result.photoError).toBe(failure);
  });

  it('throws, and sends no photo, when the fields are refused', async () => {
    const calls = api({
      create: vi.fn(async () => Promise.reject(new ApiError(422, 'validation_failed'))),
    });

    await expect(
      saveCandidateWithPhoto(calls, target, body, body, { kind: 'set', file }),
    ).rejects.toBeInstanceOf(ApiError);
    expect(calls.upload).not.toHaveBeenCalled();
  });

  it('updates the candidate that was created when the previous photo failed', async () => {
    const created = candidate();
    const calls = api();
    await saveCandidateWithPhoto(calls, { ballot: 'b1', candidate: created }, body, body, {
      kind: 'set',
      file,
    });

    expect(calls.create).not.toHaveBeenCalled();
    expect(calls.update).toHaveBeenCalledWith(created.id, body);
    expect(calls.upload).toHaveBeenCalledWith(created.id, file);
  });

  it('updates an existing candidate then removes its photo', async () => {
    const existing = candidate({ photo: WITH_PHOTO });
    const calls = api({ update: vi.fn(async () => existing) });
    const result = await saveCandidateWithPhoto(
      calls,
      { ballot: 'b1', candidate: existing },
      body,
      body,
      { kind: 'remove' },
    );

    expect(calls.remove).toHaveBeenCalledWith(existing.id);
    expect(result.candidate.photo).toBeNull();
  });

  it('does not send a removal for a candidate with no photo', async () => {
    const calls = api();
    await saveCandidateWithPhoto(calls, { ballot: 'b1', candidate: candidate() }, body, body, {
      kind: 'remove',
    });

    expect(calls.remove).not.toHaveBeenCalled();
  });
});
