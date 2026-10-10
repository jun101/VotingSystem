# Slice 06b2 — Party logo

Brief · 2026-10-10 · branch `slice/06-ballots-parties-candidates`

## Goal

A party can have a logo, chosen in the party modal (as the mockup shows), shown on the parties card and later on the
voter's ballot. It was in 06d; Jun asked for it with the parties. 06d keeps the candidates' photos and reuses the
image code written here.

## Requirements covered

FR-CAND-01 (logo), FR-CAND-04 (JPEG, PNG or WebP, 5 MB, re-encoded, resized, original never served), NFR-SEC-06,
FR-SEC-06.

## Read before coding

[PUT](../api/parties/PUT-parties-{party}-logo.md) and [DELETE](../api/parties/DELETE-parties-{party}-logo.md) endpoint
files · the election cover: `api/app/Http/Controllers/Elections/CoverController.php` and the image service it uses (the
same re-encoder, other sizes) · [frontend.md](../design/frontend.md) 1.5 item 12 · the mockup
[material-party-modal.html](../design/mockups/material-party-modal.html) (the logo row) · the slice 06b web code.

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Upload | `PUT /parties/{party}/logo`: the cover's flow with other sizes (96 and 192 pixels wide), `logo_file` already exists on `parties`; locks the party row so two uploads at once end with one logo and no orphan file; old files deleted after the commit; order of checks 404, 409, then the file |
| Remove | `DELETE /parties/{party}/logo`: 204 with or without a logo |
| Resource | `PartyResource.logo` is `{sm, md}` (paths under `/media/`) or `null` |
| Party delete | `DELETE /parties/{party}` also deletes the logo files after the commit |
| Duplicate | Does not copy the logo (already so) |
| Rate limits | Upload 20 an hour per user (as the cover), removal with the party writes |
| Isolation | The two routes are in `Tenancy::TENANT_ROUTES` |

### 2. Web — in the party modal and on the card

| Item | Requirement |
|---|---|
| Modal row | "Logo (facultatif)": a drop area that is also a button opening the file chooser (`party-form-logo-input` is the real file input), accepts JPEG, PNG, WebP; a preview (`party-form-logo-preview`) of the chosen file at once (an object URL, revoked on close) or of the saved logo; `party-form-logo-remove`; the 5 MB limit is checked in the browser before sending, with `party-form-error-logo`; a server refusal (413, 415, 422) shows its message in the same place and keeps the modal open |
| Saving | A new party is created, then its logo uploaded; if the upload fails the party stays created and the modal stays open on the logo error. An edited party: fields saved, then the logo sent or removed only if it changed |
| Card | The swatch shows the logo (`party-logo-{n}`, an `img` with the `sm` path, `alt=""` because the name is next to it) on a neutral background instead of the acronym; without a logo, as before |
| Text | French and English messages; no raw colour; the image never exceeds its box |

### 3. Tests

Written before the code, never edited by the coder: `api/tests/Acceptance/Slice06/PartyLogoTest.php`,
`web/e2e/slice06/party-logo.spec.ts`. The coder adds unit tests for the file check and the save sequence.

## Done when

`make lint`, unit tests and both acceptance files pass with no edit; generated files regenerated; the modal looked at
with a logo at 1920 × 1080 and 360 × 780.
