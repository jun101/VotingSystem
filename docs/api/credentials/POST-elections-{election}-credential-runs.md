# POST /api/v1/elections/{election}/credential-runs

Starts a run: gives a code to the voters of the scope who have none and writes their slips, one PDF per group.

| | |
|---|---|
| Slice | 10 |
| Requirements | FR-CRED-01, FR-CRED-02, FR-CRED-04, FR-CRED-05, FR-CRED-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is a **draft, scheduled or open** |

Body, JSON:

| Field | Type | Rules |
|---|---|---|
| scope | string | Required. `all` (every group and the voters with no group), `group` (one group) or `ungrouped` (the voters with no group) |
| group | UUID | Required when `scope` is `group`: a group **of this election**. Ignored for the other scopes |

```json
{ "scope": "group", "group": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60" }
```

Only the voters of the scope **who have no code yet** are served. A voter who has a code is left as is: to replace a
code, [reissue it](POST-voters-{voter}-credential.md). Codes are drawn from the alphabet and with the
length given in [the slice brief](../../slices/10-codes-and-slips.md); only their HMAC-SHA-256 is stored.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `scope` `all` with voters in two groups and some with no group | 202 (one file per group with voters to serve, the ungrouped included, all `queued`) | | |
| 2 | `scope` `group` with a group of this election | 202 (one file for that group) | | |
| 3 | `scope` `ungrouped` | 202 (one file, `group` null) | | |
| 4 | A group of more than 1 000 voters to serve | 202 (several files, `part` 1 to n, 1 000 voters each, in name order) | | |
| 5 | A group where every voter already has a code | 202 for the other groups; no file for this one | | |
| 6 | The job runs | The files become `ready`; the voters get a code; each file holds the slips of its voters; a voter's code is shown nowhere else | | |
| 7 | The job fails on one file | That file `failed` with `generation_failed`; its voters have **no** code; the other files are not affected; a new run serves them | | |
| 8 | Every voter of the scope already has a code, or the election has no voter | 409 | `nothing_to_generate` | |
| 9 | A run of this election is still `queued` | 409 | `run_in_progress` | |
| 10 | The election is closed, published or archived | 409 | `election_codes_locked` | |
| 11 | `scope` missing or not one of the three; `scope` `group` with no `group`; `group` not a UUID, unknown, another election's or another institution's (one answer for the last three) | 422 | `validation_failed` (`scope: required`, `scope: invalid`, `group: required`, `group: invalid`) | |
| 12 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 13 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 14 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 15 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 16 | Too many requests | 429 | `too_many_attempts` | |
| 17 | Another method than the ones this path takes | 405 | `method_not_allowed` | |
| 18 | Body is not valid JSON | 400 | `malformed_request` | |

## Responses

### 202 — scenarios 1 to 5

```json
{
  "data": {
    "files": [
      { "id": "5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13", "kind": "slips_pdf", "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" }, "part": 1, "parts": 1, "voter_count": 38, "status": "queued", "failure": null, "size_bytes": null, "expires_at": "2026-10-12T14:00:00Z", "created_at": "2026-10-10T19:12:05Z" }
    ],
    "voters": 38,
    "skipped": 2
  }
}
```

`voters` is the number of voters who will get a code, `skipped` the voters of the scope who already have one. The
answer comes before the work: the files are polled with [GET generated-file](GET-generated-files-{file}.md).

The file object:

```json
{
  "id": "5b0e7c1a-3d2f-4e58-9a61-0c7d4b2e8f13",
  "kind": "slips_pdf",
  "group": { "id": "4a1f8c52-6d0e-4b79-8f1a-3c9d2e7b5a60", "name": "4e année" },
  "part": 1,
  "parts": 1,
  "voter_count": 38,
  "status": "ready",
  "failure": null,
  "size_bytes": 148230,
  "expires_at": "2026-10-12T14:00:00Z",
  "created_at": "2026-10-10T19:12:05Z"
}
```

`group` is `null` for the voters with no group. `status` is `queued`, `ready` or `failed`; `failure` is `null` or
`generation_failed`. `size_bytes` is `null` until the file is ready. `part` and `parts` count the pieces of a group of
more than 1 000 slips. `expires_at` is the end of the election: the file is deleted after it. No path, no stored
name and no numeric id is output.

### 409, 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md). Order of the checks: the election (404), the body (422), the
state (409; scenario 10 before 9, 9 before 8).

## Notes

Request: the election row is locked, the files are created `queued`, the job is dispatched after the commit. Job: each
file in its own transaction with the election row locked first; the codes of the file's voters and the PDF are written
together, so a code that no file shows is never kept. A voter who got a code between the request and the job (another
run cannot start, but a reissue can) is skipped by the job and the file lists only the voters it served; the file's
`voter_count` is then lowered. The job never writes a code, a hash input or a voter's name to a log.
