# GET /api/v1/voter-imports/{import}

Reads an import: its status, its summary and its progress.

| | |
|---|---|
| Slice | 08 |
| Requirements | FR-VOT-03, FR-VOT-04 |
| Caller | Institution user: owner or manager |
| Rate limit | 240 requests per hour per user; the progress poll is every two seconds at most |

## Request

| Parameter | Type | Rules |
|---|---|---|
| import | UUID, path | An import of the caller's institution |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | An import being checked (`uploaded`) | 200 (counts at 0, `columns` empty) | | |
| 2 | A checked import (`checked`) | 200 (summary, columns, mapping, new groups) | | |
| 3 | A running import (`importing`) | 200 (`processed_rows` moves) | | |
| 4 | A finished import (`done`) | 200 (`imported_rows`, `finished_at`) | | |
| 5 | A failed check (`failed`) | 200 (`failure` is a code) | | |
| 6 | Not a UUID, unknown, or another institution's import | 404 | `not_found` | |
| 7 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 8 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 9 | Too many requests | 429 | `too_many_attempts` | |
| 10 | Another method than the ones this path takes | 405 | `method_not_allowed` | |

## Responses

### 200

```json
{ "data": {
  "id": "7d1f6e0a-3c52-4a47-9c43-0b1d5d2f9a10",
  "election": "b41f2e5e-0c4b-4b62-9a43-5d7c9f1e8a21",
  "status": "checked",
  "original_name": "eleves-4e-5e-2026.xlsx",
  "columns": ["Nom", "Classe", "Matricule", "Email", "Téléphone"],
  "mapping": { "full_name": 0, "group": 1, "identifier": 2, "email": 3, "phone": 4 },
  "total_rows": 312,
  "valid_rows": 300,
  "invalid_rows": 12,
  "duplicate_rows": 4,
  "new_groups": ["4e année", "5e année", "Terminale A", "Terminale B"],
  "processed_rows": 0,
  "imported_rows": 0,
  "failure": null,
  "has_error_file": true,
  "created_at": "2026-10-10T18:02:11Z",
  "finished_at": null
} }
```

| Field | Notes |
|---|---|
| status | `uploaded` (queued or being checked), `checked`, `importing`, `done`, `failed` |
| election | The election's UUID (a field named `election`, never `election_id`) |
| columns | The header cells of the file, as text, in file order |
| mapping | Voter field (`full_name`, `group`, `identifier`, `email`, `phone`) to the **index** (from 0) in `columns`, or `null` for "do not import". Proposed by the check, changed with [PATCH](PATCH-voter-imports-{import}.md) |
| total_rows | Data rows read (empty rows are skipped and not counted) |
| valid_rows, invalid_rows | `valid_rows + invalid_rows = total_rows`; `duplicate_rows` is the part of `invalid_rows` that is a duplicate |
| new_groups | Group names the import would create (first spelling in file order), at most 100 |
| processed_rows | While `importing`, rows handled so far (valid and rejected), for the progress bar |
| imported_rows | Voters written (`done`); rows that turned invalid at import time are in `invalid_rows` and the error file |
| failure | `null`, or `no_header`, `too_many_rows`, `no_full_name_column`, `unreadable`, `import_failed` (status `failed`) |
| has_error_file | `invalid_rows > 0` and the status is `checked` or `done` |

### 404, 401, 403, 429, 405

The shared error shape of [API conventions](../README.md).
