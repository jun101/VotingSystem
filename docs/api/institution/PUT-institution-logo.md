# PUT /api/v1/institution/logo

Replaces the institution's logo. The server re-encodes the image and keeps only the
re-encoded versions; the uploaded file is never stored or served.

| | |
|---|---|
| Slice | 04 |
| Requirements | FR-INST-02, FR-CAND-04, NFR-SEC-06 |
| Caller | Institution user: owner |
| Rate limit | 10 requests per hour per user |

## Request

`multipart/form-data` with one part:

| Field | Type | Rules |
|---|---|---|
| file | file | Required. JPEG, PNG or WebP, **recognised by its content**, not by its name or the declared type. At most 5 MB (5 242 880 bytes). At most 8 000 pixels on a side and 40 million pixels in all |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid JPEG, PNG or WebP | 200 | | |
| 2 | A logo already exists | 200 | | |
| 3 | `file` missing | 422 | `validation_failed` (`file: required`) | |
| 4 | More than 5 MB | 413 | `file_too_large` | |
| 5 | The content is not a JPEG, PNG or WebP (a GIF, a PDF, a script renamed `.png`) | 415 | `file_type_not_allowed` | |
| 6 | A JPEG, PNG or WebP that cannot be decoded (corrupt, truncated) | 415 | `file_type_not_allowed` | |
| 7 | Larger than 8 000 pixels on a side, or 40 million pixels | 422 | `validation_failed` (`file: dimensions`) | |
| 8 | The user is a manager | 403 | `forbidden` | |
| 9 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 10 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 11 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 12 | Too many requests | 429 | `too_many_attempts` | |
| 13 | Another method than PUT, DELETE | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

The same body as [GET /institution](GET-institution.md), with the new `logo`.

### 413 — scenario 4

```json
{ "error": { "code": "file_too_large", "message": "Le fichier dépasse 5 Mo." } }
```

### 415 — scenarios 5 and 6

```json
{ "error": { "code": "file_type_not_allowed", "message": "Le fichier doit être une image JPEG, PNG ou WebP." } }
```

### 422, 403, 401, 419, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table.

## Side effects

- Three WebP files are written to public storage, named `{uuid}-{size}.webp` with a new
  random UUID: `64`, `160` and `480` pixels on the longest side, ratio kept, never enlarged
  (a smaller source gives its own size for every version). Metadata (EXIF, GPS, colour
  profile) is dropped; the rotation in the EXIF data is applied first. An animated image
  keeps its first frame.
- The UUID is stored in `institutions.logo_file`, then the files of the previous logo are
  deleted. If the re-encoding fails nothing changes: the previous logo stays.

## Notes

- A file that PHP itself refuses for its size answers 413 as well.
- No original name, no path and no file content appears in an answer or in a log.
- Slice 06 reuses the same re-encoding for candidate photos.
