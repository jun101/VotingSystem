# POST /api/v1/platform/institutions/{institution}/reactivate

Lifts a suspension.

| | |
|---|---|
| Slice | 18 |
| Requirements | FR-ADM-01, FR-INST-06, FR-AUD-01, NFR-SEC-04 |
| Caller | **Platform admin** |
| Rate limit | 60 requests per hour per admin (shared with [suspend](POST-platform-institutions-{institution}-suspend.md)) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| institution | UUID | An institution |

No body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A suspended institution | 200 | | |
| 2 | The institution is not suspended | 409 | `not_suspended` | |
| 3 | Unknown institution, or not a UUID | 404 | `not_found` | |
| 4 | An owner or a manager | 403 | `forbidden` | |
| 5 | Not signed in | 401 | `unauthenticated` | |
| 6 | CSRF token missing | 419 | `csrf_mismatch` | |
| 7 | More than 60 requests in the hour | 429 | `too_many_attempts` | |
| 8 | Another method | 405 | `method_not_allowed` | |

Order of the checks: as for suspend (401, 403, 404, 429, 409).

## Responses

### 200

The institution as in [GET /platform/institutions](GET-platform-institutions.md) (`status` `active`, `suspended_at`
and `suspension_reason` `null`).

## Side effects

- `suspended_at` and `suspension_reason` are cleared. Users sign in again; open elections take votes again.
- The scheduler's next run applies the usual rules: an election whose end passed while paused **closes then** (the end
  time was not moved), one whose start passed opens.
- Every owner is emailed, in the institution's language (no reason in this email).
- An audit entry `institution.reactivated` is written as for suspend, with `details` `{ "reason": "<the reason that was lifted>" }`.
