# Slice 04 — Profile and users: what was done

2026-10-08 · branch `slice/04-profile-and-users`

Two-factor authentication, the second half of FR-INST-04, is **not** in this slice: it is slice
04b (decision of 2026-10-07).

## What you can do now

1. `make up`, sign in, open **Établissement** in the side menu (two clicks from the dashboard).
2. **Profile card:** change the name, type, city, phone, contact email, time zone, default
   language, short description and address, then Save. A wrong phone number or email is
   shown under its field, in your language.
3. **Logo:** Change picks a JPEG, PNG or WebP (5 MB at most). It appears at once in the page and
   in the side menu in place of the initials. Retirer removes it. A file that is not a picture
   (or an animated one) is refused with a message and the old logo stays.
4. **Public address:** the line `…/institutions/{id}` with a copy button and "Cette page sera
   disponible bientôt" (the page itself is slice 16).
5. **Users card:** a card per user (role, "Vous" on yourself), a card per pending or expired
   invitation. **Inviter** opens a small form (email, owner or manager). **Annuler** cancels an
   invitation; **Retirer** opens a dialog naming the person.
6. The invited person gets an email (open Mailpit), clicks the button, chooses a name and a
   password at `/accept-invitation`, and lands in the admin area, already verified, with the
   invited role.
7. A **manager** sees the profile read-only and a note ("Seul un propriétaire gère…") instead of
   the users card.

## What was built

| Part | Content |
|---|---|
| API | Ten endpoints, exactly as their files: `GET`/`PATCH /institution`, `PUT`/`DELETE /institution/logo`, `GET /users`, `DELETE /users/{user}`, `GET`/`POST /invitations`, `DELETE /invitations/{invitation}`, `POST /auth/accept-invitation` |
| Database | `invitations` (uuid, hashed token, role, expiry, accepted time), a tenant model |
| Images | PHP GD with WebP in the API image; one re-encoder (content check, size and pixel limits read before decoding, EXIF rotation for JPEG, all metadata dropped, 64/160/480 px WebP, original never kept) that slice 06 reuses for candidate photos |
| Media | A `media` volume written by the API, served by the proxy at `/media/*`: existing `.webp` files only, immutable cache, strict CSP, no listing |
| Users | Invitation email in the institution's language (names the institution, never the inviter), seven days, one live invitation per address; removal soft-deletes, frees the address, deletes the person's pending tokens and their unaccepted invitations; the last owner cannot be removed |
| Web | `/admin/institution` and `/accept-invitation`; new `Select`, `Textarea` and `ConfirmDialog` (focus trap, Escape, focus returned); the menu shows the logo |
| Tests | 211 acceptance and feature tests for the slice, 78 browser tests; the tenant suite lists the new routes and the new model |

## Decisions along the way

- **Cut:** two-factor moves to slice 04b. **Logo:** GD in the request, no queue. **Invitation:**
  the invited person sets name and password; an address that already has an account cannot be
  invited. **Public page:** only its address for now (all confirmed by Jun, 2026-10-07).
- **Review (confirmed by Jun, 2026-10-08):** animated pictures refused with 415; the memory for the
  big decode is raised only around the decode; the free-text institution name in the email is an
  accepted, documented risk; the role check comes before the rate limit.
- A user can remove themself when another owner exists; removal rewrites the email and keeps the
  name for the audit log; the profile is readable by managers; invitations last seven days
  (proposed in the endpoint files; not vetoed so far).
- The accept-invitation route sends no rate-limit counter headers, so "the same answer for every
  cause" holds byte for byte.
- The time zone select uses a static list of PHP's identifiers, checked by a test against PHP.

## Changed along the way

- Slice 03 tests corrected after this slice made them wrong: the route-file check passed its
  message to Pest as a second needle (it only started failing once real routes were listed), and
  two browser tests still expected the "not available yet" notice on the now-real institution
  page. No test of this slice was edited by the coder.
- Endpoint files changed after the review, before the code: the removal of a user deletes their
  unaccepted invitations; animated pictures are refused; EXIF rotation is JPEG only; accepting
  replaces the session already open in that browser; owner-only routes check the role before the
  rate limit.

## Review

Two reviews (correctness, security with the id-leak checklist): 5 security findings and 11
correctness findings, all fixed except one accepted (the institution name in the email). No id
leak. See `04-profile-and-users.review.md`.

## Checks

- `make check` passes: 531 API tests, 131 web unit tests, 336 browser tests (6 skipped, each for
  the other screen size), generated files up to date, build and audit.
- The screen was compared with the mockup "Établissement et utilisateurs" at 1280 px and 320 px:
  the same two cards (profile, users with invitations) and the public address; the logo, the role
  pills and "Vous" are as designed; on a phone the cards stack. Differences: the Save button is at
  the end of the form, not in the page header, and there is no card of published results (slice 16).
- **Known small points:** at 320 px the top bar title is cut ("Étab…") next to the verification chip
  and the user button; the "100 of N" count is read at page load and does not follow additions or
  removals made in the open page.
- **Not verified:** a very large upload at the limit on the real Octane workers under load; the
  Redis limiters at real limits (still open from slice 02).

## Next

Slice 04b: two-factor authentication (setup, confirm, disable, recovery codes, the extra step at
sign-in). Before it: your checkpoint on the pages above, the pull request and the merge.
