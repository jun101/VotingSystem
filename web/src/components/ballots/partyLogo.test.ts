import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import type { Party } from '@/lib/api/parties';
import { LOGO_MAX_BYTES, logoFileProblem, savePartyWithLogo, type PartySaveApi } from './partyLogo';

const party = (changes: Partial<Party> = {}): Party => ({
  id: '6e1c0a52-0000-4000-8000-000000000001',
  name: 'Avenir',
  acronym: null,
  colour: '#5468D4',
  logo: null,
  candidates_count: 0,
  created_at: '2026-10-10T10:00:00Z',
  updated_at: '2026-10-10T10:00:00Z',
  ...changes,
});

const WITH_LOGO = { sm: '/media/a-96.webp', md: '/media/a-192.webp' };
const body = { name: 'Avenir', acronym: null, colour: '#5468D4' };
const file = new File(['x'], 'logo.png', { type: 'image/png' });

function api(over: Partial<PartySaveApi> = {}): PartySaveApi {
  return {
    create: vi.fn(async () => party()),
    update: vi.fn(async () => party()),
    upload: vi.fn(async () => party({ logo: WITH_LOGO })),
    remove: vi.fn(async () => undefined),
    ...over,
  };
}

describe('logoFileProblem', () => {
  it('accepts JPEG, PNG and WebP up to 5 MB', () => {
    for (const type of ['image/jpeg', 'image/png', 'image/webp']) {
      expect(logoFileProblem({ size: LOGO_MAX_BYTES, type })).toBeNull();
    }
  });

  it('refuses a file over 5 MB', () => {
    expect(logoFileProblem({ size: LOGO_MAX_BYTES + 1, type: 'image/png' })).toBe('file_too_large');
  });

  it('refuses another type, and leaves an unknown type to the API', () => {
    expect(logoFileProblem({ size: 10, type: 'application/pdf' })).toBe('file_type_not_allowed');
    expect(logoFileProblem({ size: 10, type: 'image/gif' })).toBe('file_type_not_allowed');
    expect(logoFileProblem({ size: 10, type: '' })).toBeNull();
  });
});

describe('savePartyWithLogo', () => {
  it('creates a party, then uploads its logo', async () => {
    const calls = api();
    const result = await savePartyWithLogo(calls, { election: 'e1', party: null }, body, {
      kind: 'set',
      file,
    });

    expect(calls.create).toHaveBeenCalledWith('e1', body);
    expect(calls.upload).toHaveBeenCalledWith(party().id, file);
    expect(result.party.logo).toEqual(WITH_LOGO);
    expect(result.logoError).toBeNull();
  });

  it('creates a party without a logo and sends nothing more', async () => {
    const calls = api();
    await savePartyWithLogo(calls, { election: 'e1', party: null }, body, { kind: 'keep' });

    expect(calls.upload).not.toHaveBeenCalled();
    expect(calls.remove).not.toHaveBeenCalled();
  });

  it('keeps the created party when the upload fails, and gives the error back', async () => {
    const failure = new ApiError(415, 'file_type_not_allowed');
    const calls = api({ upload: vi.fn(async () => Promise.reject(failure)) });
    const result = await savePartyWithLogo(calls, { election: 'e1', party: null }, body, {
      kind: 'set',
      file,
    });

    expect(result.party.id).toBe(party().id);
    expect(result.logoError).toBe(failure);
  });

  it('throws, and sends no logo, when the fields are refused', async () => {
    const calls = api({
      create: vi.fn(async () => Promise.reject(new ApiError(422, 'validation_failed'))),
    });

    await expect(
      savePartyWithLogo(calls, { election: 'e1', party: null }, body, { kind: 'set', file }),
    ).rejects.toBeInstanceOf(ApiError);
    expect(calls.upload).not.toHaveBeenCalled();
  });

  it('updates an existing party then removes its logo', async () => {
    const existing = party({ logo: WITH_LOGO });
    const calls = api({ update: vi.fn(async () => existing) });
    const result = await savePartyWithLogo(calls, { election: 'e1', party: existing }, body, {
      kind: 'remove',
    });

    expect(calls.update).toHaveBeenCalledWith(existing.id, body);
    expect(calls.remove).toHaveBeenCalledWith(existing.id);
    expect(result.party.logo).toBeNull();
  });

  it('does not send a removal for a party with no logo', async () => {
    const calls = api();
    await savePartyWithLogo(calls, { election: 'e1', party: party() }, body, { kind: 'remove' });

    expect(calls.remove).not.toHaveBeenCalled();
  });
});
