# POST /api/v1/elections

Creates a draft election.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01, FR-ELEC-02 |
| Caller | Institution user: owner or manager |
| Rate limit | 60 requests per hour per user |

## Request

| Field | Type | Required | Rules |
|---|---|---|---|
| title | string | yes | 1 to 200 characters after trimming |
| description | string, null | no | at most 5 000 characters; an empty string is stored as `null` |
| starts_at | date-time | yes | ISO 8601, read as UTC (`2026-10-12T12:00:00Z`; an offset is converted) |
| ends_at | date-time | yes | after `starts_at` |
| timezone | string | no | a valid IANA identifier; default: the institution's time zone |
| language | string | no | `fr` or `en`; default: the institution's language |
| candidate_order | string | no | `manual` (default) or `shuffled` |
| results_display | string | no | `full` (default) or `winners` |

```json
{ "title": "Conseil des élèves 2026", "starts_at": "2026-10-12T12:00:00Z", "ends_at": "2026-10-16T19:00:00Z" }
```

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | Valid request, defaults used | 201 | | |
| 2 | Valid request with every field | 201 | | |
| 3 | `title`, `starts_at` or `ends_at` missing, or `title` blank | 422 | `validation_failed` (`title: required`) | |
| 4 | `title` longer than 200, `description` longer than 5 000 | 422 | `validation_failed` (`title: max`) | |
| 5 | `starts_at` or `ends_at` not a date-time | 422 | `validation_failed` (`starts_at: date`) | |
| 6 | `ends_at` not after `starts_at` | 422 | `validation_failed` (`ends_at: after_start`) | |
| 7 | `timezone` not a known identifier, `language`, `candidate_order` or `results_display` not one of the choices | 422 | `validation_failed` (`timezone: timezone`, `language: in`, …) | |
| 8 | A start in the past | 201 (a draft may have any dates; scheduling checks them in slice 09) | | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Body is not valid JSON | 400 | `malformed_request` | |
| 13 | Too many requests | 429 | `too_many_attempts` | |
| 14 | Another method than GET, HEAD, POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1, 2 and 8

The election resource of [GET /elections/{election}](GET-elections-{election}.md), `status` `draft`, `cover` `null`.

### 422, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Notes

- The election belongs to the caller's institution whatever the body says; unknown fields (`status`, `institution`,
  `id`, …) are ignored, so a request cannot create anything but a draft.
- Times are stored in UTC and shown in the election's time zone (FR-ELEC-02); the screen converts.
- Nothing is logged but the request id and the outcome.
