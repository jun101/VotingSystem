# GET /api/v1/audit/export

The audit log as an Excel or CSV file.

| | |
|---|---|
| Slice | 17 |
| Requirements | FR-AUD-03, NFR-SEC-06 |
| Caller | Institution **owner** |
| Rate limit | 20 requests per hour per user |

## Request

Query: `format` (required, `csv` or `xlsx`) and the filters of [GET /audit](GET-audit.md) (`action`, `election`, `user`,
`from`, `to`).

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | `format=xlsx` or `csv`, the owner | 200, one row per entry, newest first, at most 10 000 | | |
| 2 | The filters of the list | 200 (the same rows as the list) | | |
| 3 | A value that could run as a formula | The cell is quoted by `FormulaGuard` | | |
| 4 | `format` missing or unknown, a bad date | 422 | `validation_failed` | |
| 5 | A manager | 403 | `forbidden` | |
| 6 | Not signed in, suspended institution, too many requests, another method | 401, 403, 429, 405 | the shared codes | |

## Responses

### 200

`Content-Disposition: attachment; filename="journal-{slug of the institution}.xlsx|csv"` (`audit-log-…` when the
institution's language is `en`), `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`. CSV: UTF-8 byte order
mark, comma, `\r\n`. Columns `Date (UTC)`, `Action`, `Personne`, `Élection`, `Objet`, `Détails`, `Adresse IP` (`When
(UTC)`, `Action`, `Person`, `Election`, `Subject`, `Details`, `IP address` in English). `Action` is the code;
`Détails` is the details as compact JSON.
