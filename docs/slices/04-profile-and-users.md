# Slice 04 — Profile and users

Brief · 2026-10-07 · branch `slice/04-profile-and-users`

## Goal

An owner opens "Établissement" (screen A14) and edits the institution's profile, uploads a
logo (shown in the side menu in place of the initials), invites a manager or another owner
by email, cancels an invitation, and removes a user. The invited person opens the link in
the email, chooses a name and a password, and lands in the admin area. A manager sees the
profile but cannot change it and does not manage users.

Two-factor authentication (FR-INST-04, second half) is **not** in this slice: it is slice
04b, written after this one merges. The plan's slice 04 was cut in two on Jun's decision on
2026-10-07 (profile and users here, two-factor in 04b).

## Requirements covered

FR-INST-02 (profile), FR-INST-03 (invite, remove, at least one owner), FR-CAND-04 (image
handling: content check, 5 MB, re-encoding to fixed sizes, original never served),
NFR-SEC-06 (upload checked by content), NFR-SEC-05 (rate limits on invitations and uploads),
NFR-SEC-03 (new tenant models join the isolation suite). FR-NAV-04 is respected on A14
(cards, no table).

## Read before coding

[architecture.md](../design/architecture.md) sections 4.4 and 8 ·
[database.md](../design/database.md) sections 2.1, 5 and 6 ·
[frontend.md](../design/frontend.md) · [email.md](../design/email.md) ·
[API conventions](../api/README.md) · the ten endpoint files below · the mockup
[Institution.dc.html](../design/mockups/Institution.dc.html) (screen A14) · the slice 03
review file and `api/tests/Support/Tenancy.php`, which this slice extends.

## Endpoints (the contract)

| Endpoint | File |
|---|---|
| `GET /institution`, `PATCH /institution` | [institution/](../api/institution/) |
| `PUT /institution/logo`, `DELETE /institution/logo` | [institution/](../api/institution/) |
| `GET /users`, `DELETE /users/{user}` | [users/](../api/users/) |
| `GET /invitations`, `POST /invitations`, `DELETE /invitations/{invitation}` | [users/](../api/users/) |
| `POST /auth/accept-invitation` (public) | [auth/](../api/auth/POST-auth-accept-invitation.md) |

Slices 02 and 03 endpoints do not change.

## What to build

### 1. Server

| Item | Requirement |
|---|---|
| Roles | An owner passes; a manager gets `403 forbidden` on every write and on `GET /users`, `GET /invitations`. A reusable policy or middleware (`owner`) so later slices use the same check. 404 for another institution's record is checked **before** 403 for the role |
| Institution profile | `institutions` already has the columns. A full `InstitutionProfileResource` (the short `InstitutionResource` of `/auth/me` is unchanged). `$fillable` adds the new fields; `suspended_at` and `logo_file` stay out of it |
| Logo | PHP **GD with WebP** added to the API image (`docker/api/Dockerfile`), `upload_max_filesize=6M` and `post_max_size=8M` in `docker/api/php.ini` (the application answers 413 above 5 MB; a PHP refusal for size answers 413 too). Image check by content (`finfo` plus a decode with GD), pixel limits checked from the header **before** decoding, EXIF rotation applied, all metadata dropped, three WebP sizes (64, 160, 480 px on the longest side, never enlarged, quality 82), written to a filesystem disk named `media` (root = the shared volume, files directly at the root as `{uuid}-{size}.webp`, so the URL is `/media/{name}`), the old files deleted after the database update. The re-encoder is a service class with no logo knowledge: slice 06 reuses it for candidate photos. Done in the request, not in a queue (Jun, 2026-10-07) |
| Media serving | A named volume `media` mounted read-write in `api`, `queue` and `scheduler`, read-only in `proxy`. The Caddyfile serves `/media/*` with `file_server` from it, `Cache-Control: public, max-age=31536000, immutable` (the file name changes with each upload), `X-Content-Type-Options: nosniff`, and no directory listing |
| Invitations | New table `invitations` (as database.md section 2.1: `uuid`, `institution_id`, `invited_by_user_id`, `email`, `role`, `token_hash`, `expires_at`, `accepted_at`, `created_at`). Model with the tenant trait. One live invitation per address and institution (a new one replaces it). Token: 64 random characters, SHA-256 stored, valid 7 days, single use, same machinery as the slice 02 tokens. Email in the inviter's institution language, naming the institution but never the inviter's name (review S6 of slice 02), queued with an encrypted payload, following `email.md` and added to `MailDesignTest` |
| Accepting | `POST /auth/accept-invitation` creates the user (verified, role and institution from the invitation, language of the institution), marks the invitation accepted, signs the user in, regenerates the session. Removes the tenant scope by name, with a comment, to find the token |
| Removing a user | Soft delete (`deleted_at`), email rewritten to `removed-{uuid}@removed.invalid`, pending tokens deleted. The session user provider and the sign-in lookup ignore removed users, so the next request is 401. The last-owner rule is checked in the same transaction, with the owner rows locked |
| Rate limits | `invitations-create` 20 per hour per user, `logo-upload` 10 per hour per user, `auth-accept-invitation` 10 per hour per IP; all multiplied by `AUTH_RATE_LIMIT_FACTOR` like the others |
| Logs | Nothing logged but the request id and the outcome; never an email, a token, a file name or file content |
| Generated files | `docs/api/openapi.json` and `schema.d.ts` regenerated, never by hand |

### 2. The isolation suite grows

`api/tests/Support/Tenancy.php` is extended with the new routes (`/institution`,
`/institution/logo`, `/users/{user}`, `/invitations`, `/invitations/{invitation}`), the new
tenant model (`Invitation`) and table (`invitations`). `institutions` has no scope (it is
the tenant) and is already listed with its reason. The coverage tests keep failing on a
route without a test, and on a tenant route without `auth` and `institution.active`.

### 3. Web

| Item | Requirement |
|---|---|
| Page `/admin/institution` | Replaces the "coming soon" page for this route (screen A14, mockup "Établissement et utilisateurs"). On `lg` two columns: the profile card on the left, the users card on the right; on a phone they stack. Cards, no table |
| Profile card | Logo with "Changer" and "Retirer" (initials when there is none), name, type (select), city, phone, time zone (select of identifiers, Haiti first), default language, short description with a character count, address, contact email, one Save button. A manager sees the same fields read-only and no buttons. A saved confirmation; field errors under each field; the message of the API error code from the message files |
| Public address | One line under the profile: the institution's public address `{site}/institutions/{id}` with a copy button, and the note "Cette page sera disponible bientôt" (slice 16 builds the page). No published-results card (Jun, 2026-10-07) |
| Users card (owner only) | One card per user: initials or name, email, role pill, "Vous" on the signed-in user, a "Retirer" action with a confirmation (naming the person); one card per pending or expired invitation: email, role, "en attente depuis N jours" or "expirée", a "Annuler" action. An "Inviter" button opens an inline form (email, role) in the card. A manager sees a polite note instead: "Seul un propriétaire gère les utilisateurs et ce profil" |
| Three clicks | From the dashboard: menu → Établissement → Inviter. Two clicks to the form |
| Side menu | The logo (size `sm`) replaces the initials of the institution when there is one; an `img` with empty `alt` (the name is next to it). After an upload or removal the menu updates without a reload |
| Accept page | `/accept-invitation?token=…` in the A01 layout: name and password, show/hide toggle as register; an explanation of who invited whom is **not** shown (the API does not say, to keep the token page quiet). Errors: 404 and 410 give a clear page with a link to sign in; 409 says the address is already used. On success: `/admin`, the page `lang` follows the stored language (use the `router.refresh()` pattern of the sign-in form) |
| Messages | Every text in the French and English files, none in a component |
| Motion and tokens | Reveal on the cards from the slice 01b kit, off under reduced motion; tokens only |
| Accessibility | Labels, `aria-describedby` for errors, focus moved to the first error after a failed save, confirmation dialogs trap focus and return it; usable from 320 px by keyboard alone |
| Images | A plain `img` from `/media/…` is allowed (the files are already optimised); width and height set |

### 3b. Names the browser tests rely on

| Where | `data-testid` values |
|---|---|
| Page | `institution-page`, `profile-card`, `users-card`, `users-manager-note` (manager only), `public-address`, `public-address-copy` |
| Profile | `profile-name`, `profile-type`, `profile-city`, `profile-phone`, `profile-timezone`, `profile-language`, `profile-description`, `profile-address`, `profile-contact-email`, `profile-save`, `profile-saved`, `profile-form-error`, and one error per field `profile-<field>-error` |
| Logo | `logo-preview` (the `img`, absent without a logo), `logo-initials`, `logo-input` (the file input), `logo-remove`, `logo-error`, and in the menu `menu-institution-logo` (absent without a logo) |
| Users | `user-card-<n>` (n from 1 in list order), `user-name-<n>`, `user-email-<n>`, `user-role-<n>`, `user-you` (on the signed-in user), `user-remove-<n>`, `user-remove-dialog` (the confirmation, naming the person), `user-remove-confirm`, `user-remove-cancel`, `user-remove-error` (inside the dialog; the buttons `user-remove-<n>` match `^user-remove-\d+$`) |
| Invitations | `invite-open`, `invite-form`, `invite-email`, `invite-role`, `invite-submit`, `invite-email-error`, `invite-form-error`, `invitation-card-<n>`, `invitation-email-<n>`, `invitation-status-<n>`, `invitation-cancel-<n>` |
| Accept page | `accept-form`, `accept-name`, `accept-name-error`, `accept-password`, `accept-password-error`, `accept-submit`, `accept-invalid` (404), `accept-expired` (410), `accept-taken` (409), `form-error` |

Words checked by tests, French first: role pills "Propriétaire" / "Gestionnaire" and "Owner" /
"Manager"; the manager note holds "Seul un propriétaire" / "Only an owner"; the invitation
status holds "en attente" or "expirée" / "pending" or "expired".

## Rules checked by tests

1. Every endpoint scenario of the ten files, one test per scenario.
2. **Isolation:** another institution's user or invitation answers 404 with the same body as
   an unknown one; a list shows only the caller's rows; a manager of institution A cannot
   do an owner's action on A (403) and cannot see B at all (404 order).
3. **Last owner:** two owners racing to remove each other leave exactly one owner.
4. **The logo:** a PNG, a JPEG and a WebP are accepted; a GIF, a PDF and a script named
   `.png` are refused by content; a file over 5 MB is 413; the stored files are WebP of the
   three sizes, carry no EXIF, and the upload's name and bytes are nowhere on disk; a
   rejected upload leaves the previous logo; the old files are deleted on success.
5. **Invitations:** the token is stored hashed and never returned or logged; a second
   invitation to the same address kills the first link; an expired link is 410; the link
   works once; the email follows `MailDesignTest`.
6. **Removal:** the removed user's next request is 401, they cannot sign in, and their address
   can be invited again.
7. **No id leaves the server:** the id-leak test passes on the new routes; no `logo_file` or
   numeric key in a response; media file names are the logo UUIDs only.
8. Accessibility check on A14 and the accept page at 320 and 1280 px, in both languages.
9. `/media/…` is served by the proxy with the headers above, and `/media/` lists nothing.

## Acceptance tests

Written before the code, committed on the branch before the code, **not edited during
coding**. If one seems wrong, stop and say why.

| Where | Checks |
|---|---|
| `api/tests/Acceptance/Slice04/` | One file per endpoint (`ShowInstitutionTest`, `UpdateInstitutionTest`, `UploadLogoTest`, `DeleteLogoTest`, `ListUsersTest`, `RemoveUserTest`, `ListInvitationsTest`, `CreateInvitationTest`, `CancelInvitationTest`, `AcceptInvitationTest`), plus `InvitationMailTest` (design rules), `LogoStorageTest`, and the isolation additions to `Tenancy.php` |
| `api/tests/Support/` | Image fixtures built by the tests (no binary file in the repository); helpers for a second user in an institution (exists), an invitation with a known token |
| `web/e2e/slice04/` | `institution.spec.ts` (profile, logo, manager read-only, public address), `users.spec.ts` (invite, read the email in Mailpit, accept, remove, cancel, last owner, two institutions), `quality.spec.ts` (accessibility, touch size, reduced motion). Helpers in `web/e2e/support/admin.ts` |

All earlier tests keep passing.

## Out of scope

- Two-factor authentication, recovery codes, `two_factor_enabled` in the user list: slice 04b.
- Changing one's own name, email or password while signed in (not in the spec).
- Changing a user's role, transferring ownership beyond inviting an owner.
- The public institution page and published results (slice 16); the audit log of these
  actions (slice 17: it will read the events this slice makes, which are not recorded yet).
- Candidate photos (slice 06): only the shared re-encoder exists.
- Deleting the institution (platform side, slice 18).

## Done when

1. `make check` passes entirely, with every earlier test.
2. An owner does the whole goal in a browser, in French and English, at desktop and phone
   width, including reading the invitation email in Mailpit; a manager sees what they may.
3. The isolation suite fails when a route, a model or a table is added without a test.
4. `docs/api/openapi.json` and `schema.d.ts` are up to date.
5. CI passes; no reviewer finding is left open. Review: `correctness-reviewer` and
   `security-reviewer` (with the id-leak checklist); the upload and the invitation token are
   the places to read hardest.

## Decisions taken in this brief

Confirmed by Jun on 2026-10-07.

- **Cut:** two-factor moves to slice 04b.
- **Logo:** GD with WebP in the API image; re-encoded inside the upload request, not queued.
- **Invitation:** the invited person sets a name and a password; the link proves the address,
  so the account starts verified; an address with an account cannot be invited
  (an address belongs to one institution).
- **Public page:** only its address is shown on A14, until slice 16.

Proposed in the endpoint files, for Jun to veto at the checkpoint: a user can remove
themself when another owner exists; removing rewrites the email and keeps the name for the
audit log; a manager can read the profile but not change it; invitations last 7 days.
