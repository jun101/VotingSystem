# POST /api/v1/platform/institutions/{institution}/suspend

Suspends an institution, with a reason.

| | |
|---|---|
| Slice | 18 |
| Requirements | FR-ADM-01, FR-INST-06, FR-AUD-01, NFR-SEC-04 |
| Caller | **Platform admin** |
| Rate limit | 60 requests per hour per admin (the two POST routes of `/platform` share it) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| institution | UUID | An institution |

| Field | Type | Required | Rules |
|---|---|---|---|
| reason | string | yes | trimmed, control characters removed, 3 to 500 characters |

```json
{ "reason": "Paiement non reçu" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An active institution, a valid reason | 200 | | |
| 2 | `reason` missing, empty after trimming, shorter than 3 or longer than 500 | 422 | `validation_failed` (`reason: required`, `min`, `max`) | |
| 3 | The institution is already suspended | 409 | `already_suspended` | |
| 4 | Unknown institution, or not a UUID | 404 | `not_found` | |
| 5 | An owner or a manager | 403 | `forbidden` | |
| 6 | Not signed in | 401 | `unauthenticated` | |
| 7 | CSRF token missing | 419 | `csrf_mismatch` | |
| 8 | More than 60 requests in the hour | 429 | `too_many_attempts` | |
| 9 | Another method; body not valid JSON | 405, 400 | `method_not_allowed`, `malformed_request` | |

Order of the checks: session (401), role (403), institution (404), rate limit (429), body (422), state (409).

## Responses

### 200

The institution as in [GET /platform/institutions](GET-platform-institutions.md) (`status` `suspended`).

## Side effects

- `suspended_at` is set to now and `suspension_reason` to the reason, in one transaction with the audit entry.
- Their sessions end at their next request (the API already answers 403 `institution_suspended`); nothing else is purged.
- **Open elections are paused**: no vote is taken, no election is opened or closed by the scheduler, nothing is changed
  in them and their end time does not move. A voter reading one sees the phase `paused`
  ([GET /vote/{election}](../vote/GET-vote-{election}.md)).
- Every **owner** of the institution is emailed, in the institution's language, with the reason.
- An audit entry `institution.suspended` is written with `institution_id` null (the platform's own log, not the
  owner's), `subject` the institution, `details` `{ "reason": "…" }`, and the admin as the actor.
