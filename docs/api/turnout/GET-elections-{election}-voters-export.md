# GET /api/v1/elections/{election}/voters/export

The voters of an election as an Excel or CSV file, for example the ones who have not voted yet, to remind them.

| | |
|---|---|
| Slice | 14 |
| Requirements | FR-VOT-06, FR-VOT-07, NFR-SEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

| Query | Type | Rules |
|---|---|---|
| format | string | Required: `xlsx` or `csv` |
| voted | string | Optional: `no` (default), `yes` or `all` |
| group | UUID or `none` | Optional: one group of the election, or the voters with no group |
| q | string | Optional, as in the voters list |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `format=xlsx`, `voted=no` | 200, Excel, one row per voter who has not voted, sorted by group then name | | |
| 2 | `format=csv` | 200, CSV with a UTF-8 byte order mark and the comma of the import template | | |
| 3 | `voted=yes` or `all`, `group`, `q` | 200 (the filters of the voters list) | | |
| 4 | A name or group that starts with `=`, `+`, `-` or `@` | The cell is quoted by `FormulaGuard` | | |
| 5 | `format` missing or unknown, `voted` or `group` invalid | 422 | `validation_failed` | |
| 6 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 7 | Not signed in, suspended institution, too many requests, another method | 401, 403, 429, 405 | the shared codes | |

## Responses

### 200

`Content-Type` the Excel or CSV type, `Content-Disposition: attachment; filename="electeurs-{slug}.xlsx|csv"` (the slug
of the election's title), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. Columns `Nom complet`,
`Groupe`, `Identifiant`, `Courriel`, `Téléphone` (`Full name`, `Group`, `Identifier`, `Email`, `Phone` for an English
election), plus `A voté` / `Has voted` (`Oui` / `Non`, `Yes` / `No`) when `voted` is `all`. Rows are sorted by group name then full name (accents and case ignored), the voters without a group last. A text that could run as a formula gets a single quote in front (`FormulaGuard`), in Excel and in CSV. **No code, no hash, no link, no time of vote.**

## Notes

At most 10 000 rows (the voter limit). Written in a stream. The Excel file is staged briefly in a private temporary folder, removed afterwards (also on an error); a CSV is never kept. If reading or writing fails once the file has begun, the response is cut off rather than ended cleanly, so a client never takes a short file for a whole one.

A phone number that begins with `+` followed by digits, spaces, dots, dashes or parentheses is an international number and stays as it is; any other text that begins with `=`, `+`, `-` or `@` is guarded.
