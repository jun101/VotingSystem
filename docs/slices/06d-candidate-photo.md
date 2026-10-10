# Slice 06d — Candidate photo

Brief · 2026-10-10 · branch `slice/06d-candidate-photo`

## Goal

A candidate can have a photo, chosen in the candidate modal (the photo area of the mockup). It replaces the default
avatar on the rows and in the voter preview. The party logo already exists (06b2); this task reuses its code.

## Requirements covered

FR-CAND-02, FR-CAND-03 (no photo: avatar by sex), FR-CAND-04 (JPEG, PNG, WebP, 5 MB, re-encoded and resized, original
never served), NFR-SEC-06, FR-SEC-06.

## Read before coding

[PUT](../api/candidates/PUT-candidates-{candidate}-photo.md) and
[DELETE](../api/candidates/DELETE-candidates-{candidate}-photo.md) endpoint files · the party logo code of 06b2
(`PartyLogoController`, `ImageReEncoder::PARTY_LOGO_SIZES`, `partyLogo.ts`, the logo row of `PartyModal.tsx`) ·
[frontend.md](../design/frontend.md) 1.5 item 12 · the mockups
[material-candidate.html](../design/mockups/material-candidate.html) (photo area at the left of Identité) and
[material-candidate-modal.html](../design/mockups/material-candidate-modal.html) · the 06c candidate code.

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Upload | `PUT /candidates/{candidate}/photo`: the party logo's flow with sizes **160** and **480** px wide; election, ballot and candidate rows locked so two uploads at once leave one photo and no orphan file; old files deleted after the commit; checks in the order 404, 409, then the file; 20 an hour per user |
| Remove | `DELETE /candidates/{candidate}/photo`: 204 with or without a photo; candidate writes limiter |
| Resource | `CandidateResource.photo` is `{sm, md}` or null |
| Deletions | Deleting a candidate, a ballot or an election removes the photo files (and the party logos of an election) after the commit; a duplicate never copies photos |
| Isolation | The two routes are in `Tenancy::TENANT_ROUTES` |

### 2. Web

| Item | Requirement |
|---|---|
| Modal | In **Identité**, the photo area of the mockup: a drop area that is also a button (`candidate-form-photo-input` is the real file input), JPEG, PNG, WebP; a square preview (`candidate-form-photo-preview`) at once, also in the voter preview (`candidate-preview-photo`); `candidate-form-photo-remove`; the 5 MB limit and the type are checked in the browser; server refusals shown beside the photo (`candidate-form-error-photo`); the modal stays open on an error |
| Saving | A new candidate is created, then the photo uploaded; if the upload fails the candidate stays created and the modal stays open on the photo error; an edited candidate sends or removes the photo only if it changed (the party logo's save sequence) |
| Rows | `candidate-photo-{b}-{c}` (an `img` with the `sm` path, `alt=""`, square, rounded) replaces the avatar; without a photo the avatar as before |
| Text, tokens | French and English messages; no raw colour; the image never exceeds its box |

### 3. Tests

Written before the code, never edited by the coder: `api/tests/Acceptance/Slice06/CandidatePhotoTest.php`,
`web/e2e/slice06/candidate-photo.spec.ts`. The coder adds unit tests for the file check and the save sequence.

## Done when

`make lint`, unit tests, the whole API suite (alone) and `e2e/slice06` pass with no edit of an acceptance test; generated
files regenerated; the modal looked at with a photo at 1920 × 1080 and 360 × 780.
