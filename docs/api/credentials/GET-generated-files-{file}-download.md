# GET /api/v1/generated-files/{file}/download

The PDF of one file, decrypted on the way out.

| | |
|---|---|
| Slice | 10 |
| Requirements | FR-CRED-05, FR-CRED-08, FR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 120 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| file | UUID | A file of the caller's institution whose election is a draft, scheduled or open |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A ready file | 200 (`application/pdf`, US Letter, the file's slips) | | |
| 2 | A file that is still `queued`, or `failed` | 409 | `file_not_ready` | |
| 3 | The election was closed (the file is gone or about to be) | 404 | `not_found` | |
| 4 | Not a UUID, unknown, deleted, or another institution's (one answer for all) | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | Too many requests | 429 | `too_many_attempts` | |
| 8 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenario 1

Headers: `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="coupons-4e-annee.pdf"` (the group
name as a lower-case ASCII slug, `coupons-sans-groupe.pdf` for the voters with no group, `-partie-2` after the slug for
a part), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. The body is the PDF; the file name never holds
a UUID or a numeric id.

### 409, 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).

## Notes

Anything that can be read from the PDF is a code: the response is never cached by the proxy or the browser, and the
download is written to the log as an outcome line (user UUID, file UUID, count), never the content. The stored file is
decrypted in memory or in a stream; no clear copy is written to disk.
