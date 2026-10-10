# GET /api/v1/elections/{election}/voter-imports/template

Downloads the template file of the voter import.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user |

## Request

| Parameter | Type | Rules |
|---|---|---|
| election | UUID, path | An election of the caller's institution, in any status |
| format | string, query | `xlsx` (default) or `csv` |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `format` absent or `xlsx` | 200, an `.xlsx` file | | |
| 2 | `format=csv` | 200, a `.csv` file | | |
| 3 | `format` is something else | 422 | `validation_failed` (`format: invalid`) | |
| 4 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 5 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 6 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 7 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 8 | Too many requests | 429 | `too_many_attempts` | |
| 9 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200 — scenarios 1 and 2

Headers: `Content-Disposition: attachment; filename="modele-electeurs.xlsx"` (or `.csv`), `Cache-Control: no-store`.

The file holds the header row `nom_complet`, `groupe`, `identifiant`, `courriel`, `telephone` and three example rows
(the first as in the mockup). The `.xlsx` has a second sheet `Instructions` (one line per column, in the language of the
signed-in user). The `.csv` is UTF-8 with a byte-order mark, separator `,`. The file never holds a formula.

### 422, 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).
