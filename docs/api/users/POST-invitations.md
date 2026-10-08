# POST /api/v1/invitations

Invites a person by email to join the institution as owner or manager. If a live invitation
for the same address already exists in this institution, it is replaced: the old link
stops working and a new email is sent.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-03, NFR-SEC-05 |
| Caller | Institution user: owner |
| Rate limit | 20 requests per hour per user |

## Request

| Field | Type | Rules |
|---|---|---|
| email | string | Required. A valid address, at most 255 characters; compared in lower case |
| role | string | Required. `owner` or `manager` |

```json
{ "email": "j.pierre@etoile.example", "role": "manager" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request | 201 | | |
| 2 | A live invitation exists for the same address | 201 (replaced) | | |
| 3 | An expired invitation exists for the same address | 201 (replaced) | | |
| 4 | `email` or `role` missing | 422 | `validation_failed` (`email: required`) | |
| 5 | `email` not an address, or longer than 255 | 422 | `validation_failed` (`email: email`, `email: max`) | |
| 6 | `role` not `owner` or `manager` | 422 | `validation_failed` (`role: in`) | |
| 7 | The address already belongs to a user (any institution, any letter case) | 422 | `validation_failed` (`email: taken`) | |
| 8 | The user is a manager | 403 | `forbidden` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Body is not valid JSON | 400 | `malformed_request` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 to 3

The same body as one item of [GET /invitations](GET-invitations.md), `expired` false and
`expires_at` seven days after `created_at`.

### 422 — scenarios 4 to 7

```json
{ "error": { "code": "validation_failed", "message": "Les données envoyées ne sont pas valides.", "fields": { "email": ["taken"] } } }
```

### 403, 401, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Side effects

- One row in `invitations`: the SHA-256 of a random 64-character token, the role, the
  inviter, `expires_at` (seven days). One live invitation per address and institution.
- One email, in the **inviter's institution default language**, following
  [email.md](../../design/email.md): the greeting holds no name, the inviter's name and the
  institution's name are text in the body, one button to
  `{APP_URL}/accept-invitation?token=…` (a token and nothing else in the link), the
  validity, and an "ignore this message" line. Sent through the queue, the payload
  encrypted.

## Notes

- The "taken" answer confirms that an address has an account somewhere. It is the same
  choice as registration (slice 02) and only an owner can ask.
- Two institutions can invite the same address; whoever accepts first takes it, and the
  other invitation then fails with `email_taken` ([accept](../auth/POST-auth-accept-invitation.md)).
- The token is never in a response, a log, or any place but the email.
