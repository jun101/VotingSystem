# PUT /api/v1/elections/{election}/cover

Replaces the cover picture of a draft election. The server re-encodes it and keeps only the re-encoded versions.

| | |
|---|---|
| Slice | 05 |
| Requirements | FR-ELEC-01, FR-CAND-04, NFR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | A **draft** election of the caller's institution |

`multipart/form-data` with one part, under the same rules as the institution's logo
([PUT /institution/logo](../institution/PUT-institution-logo.md)): `file`, required, JPEG, PNG or WebP recognised by
its content, at most 5 MB, at most 8 000 pixels on a side and 40 million pixels, not animated.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid picture, no cover yet | 200 | | |
| 2 | A cover already exists | 200 (replaced) | | |
| 3 | `file` missing | 422 | `validation_failed` (`file: required`) | |
| 4 | More than 5 MB | 413 | `file_too_large` | |
| 5 | Not a JPEG, PNG or WebP by content, undecodable, or animated | 415 | `file_type_not_allowed` | |
| 6 | Larger than 8 000 pixels on a side or 40 million pixels | 422 | `validation_failed` (`file: dimensions`) | |
| 7 | The election is not a draft | 409 | `election_not_editable` | |
| 8 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than PUT, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

The election resource of [GET /elections/{election}](GET-elections-{election}.md) with the new `cover`.

### 413, 415, 422, 409, 404, 401, 403, 419, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table (bodies as for the logo).
Order of the checks: the record (404), the state (409), then the file.

## Side effects

- Two WebP files, named `{uuid}-{size}.webp` with a new random UUID, are written to the media disk: **480** and
  **960 pixels wide**, ratio kept, never enlarged, all metadata dropped, the EXIF rotation of a JPEG applied. The same
  re-encoder as the logo's, with other sizes.
- The UUID is stored in `elections.cover_file`, then the files of the previous cover are deleted. If the re-encoding
  fails nothing changes.

## Notes

No original name, path or content appears in an answer or a log. The cover is not copied by a duplicate.
