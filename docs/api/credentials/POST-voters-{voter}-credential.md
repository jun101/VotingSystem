# POST /api/v1/voters/{voter}/credential

Gives one voter a code (the first one, or a new one that replaces a lost slip) and writes a one-slip PDF.

| | |
|---|---|
| Slice | 10; the refusal for a voter who has voted is slice 12 |
| Requirements | FR-CRED-01, FR-CRED-02, FR-CRED-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 60 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| voter | UUID | A voter of the caller's institution whose election is a **draft, scheduled or open** |

No body.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A voter with no code | 201 (a code; `reissue_count` stays 0) | | |
| 2 | A voter with a code | 201 (a new code; the old one no longer matches any voter; `reissue_count` + 1) | | |
| 3 | The answer | The code in clear **once**, a ready one-slip file and the voter; the response is `Cache-Control: no-store` | | |
| 4 | The old code is used afterwards | Refused as an unknown code (slice 12 tests it with the voting endpoint) | | |
| 5 | The voter has voted (a participation exists) | 409 | `voter_has_voted` | |
| 6 | The election is closed, published or archived | 409 | `election_codes_locked` | |
| 7 | The file cannot be written | 500 `server_error` and the voter's code is unchanged | | |
| 8 | Not a UUID, unknown, deleted, or another institution's voter (one answer for all) | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 to 3

```json
{
  "data": {
    "voter": { "id": "a8b1c6d2-0f4e-4d7a-9b35-6e2f1c8d4a70", "full_name": "Wideline Joseph", "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" } },
    "code": "K7MP4-QX9RT",
    "reissue_count": 1,
    "file": { "id": "5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13", "kind": "slips_pdf", "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" }, "part": 1, "parts": 1, "voter_count": 1, "status": "ready", "failure": null, "size_bytes": 9120, "expires_at": "2026-10-12T14:00:00Z", "created_at": "2026-10-10T19:12:05Z" }
  }
}
```

This is the only answer in the API that holds a code. It is never logged (no request or response body of this route is
written to a log) and never read back: the code can be shown again only by issuing another one.

### 409, 404, 401, 403, 419, 429, 405, 500

The shared error shape of [API conventions](../README.md). Order of the checks: the voter (404), the election's state
(409).

## Notes

One transaction with the election row locked first, then the voter's credential row (created if none) is written with
the new hash, `issued_at` now and `issued_by` the user; the slip file is written inside it, so a failure keeps the old
code. A one-slip file counts in the election's files like any other and is deleted with them.

## Slice 11: email

Body (optional): `{ "email": true }`. The new code is also emailed to the voter, in the background; `email_status`
becomes `queued`. A voter with no address: 422 `validation_failed` (`email: no_address`) and nothing is changed. The
answer is the same, plus `email`: `queued` or `null`.
