# GET /api/v1/voter-imports/{import}/errors

Downloads the rejected rows as a spreadsheet to correct and import again.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03, NFR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution, status `checked` or `done`, with `invalid_rows > 0` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A checked or finished import with rejected rows | 200, an `.xlsx` file | | |
| 2 | No rejected row, or the import is `uploaded`, `importing` or `failed` | 409 | `no_error_file` | |
| 3 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 4 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 5 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 6 | Too many requests | 429 | `too_many_attempts` | |
| 7 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200

Headers: `Content-Disposition: attachment; filename="erreurs-electeurs.xlsx"`, `Cache-Control: no-store`.

One sheet. Header row: `nom_complet`, `groupe`, `identifiant`, `courriel`, `telephone`, `ligne`, `raison`; one row per
rejected row, the values as in the file (the original cell text), `ligne` the line in the uploaded file and `raison` the
reason codes in words, in the language of the signed-in user. The file has the same columns as the template, so it
can be corrected and uploaded again. **A text cell that begins with `=`, `+`, `-` or `@` is written with a single quote (`'`) in front**, so that a spreadsheet never runs it as a formula (NFR-SEC-06).

### 409, 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).
