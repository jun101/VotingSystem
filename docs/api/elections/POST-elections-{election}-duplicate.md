# POST /api/v1/elections/{election}/duplicate

Creates a new draft from an existing election, whatever its status.

| | |
|---|---|
| Slice | 05 (settings); 06a adds the ballots; 06b the parties; 06c the candidates; 07 the voters |
| Requirements | FR-ELEC-06 |
| Caller | Institution user: owner or manager |
| Rate limit | 60 requests per hour per user |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution, in any status |

Body, JSON, optional:

| Field | Type | Rules |
|---|---|---|
| title | string | 1 to 200 characters after trimming. Default: `Copie de ` followed by the source title (`Copy of ` for a source whose language is `en`), cut to 200 characters |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A draft, no body | 201 | | |
| 2 | A closed, published or archived election | 201 | | |
| 3 | A `title` is given | 201 (that title is used) | | |
| 3c | The source has parties (06b) | 201 (the new election has the same parties: name, acronym, colour, with new UUIDs) | | |
| 3b | The source has ballots (06a) | 201 (the new election has the same ballots, same order, `ballots_count` equal to the source's) | | |
| 4 | `title` blank or longer than 200 | 422 | `validation_failed` (`title: required`, `title: max`) | |
| 5 | Not a UUID, unknown, or another institution's | 404 | `not_found` | |
| 6 | Not signed in, or the session has expired | 401 | `unauthenticated` | |
| 7 | The user's institution was suspended since sign-in | 403 | `institution_suspended` | |
| 8 | CSRF token missing or wrong | 419 | `csrf_mismatch` | |
| 9 | Body is not valid JSON | 400 | `malformed_request` | |
| 10 | Too many requests | 429 | `too_many_attempts` | |
| 11 | Another method than POST | 405 | `method_not_allowed` | |

## Responses

### 201 — scenarios 1 to 3

The election resource of [GET /elections/{election}](GET-elections-{election}.md) for the **new** election: status
`draft`, a new `id`, `created_at` now, no cover, `ballots_count` and `voters_count` `0`.

### 422, 404, 401, 403, 419, 400, 429, 405

The shared error shape of [API conventions](../README.md), with the codes of the table. Scenario 5 gives the same body
and headers for every cause.

## Side effects

- Copied: description, `starts_at`, `ends_at`, `timezone`, `language`, `candidate_order`, `results_display`.
- **Not** copied: the cover (its files are not shared, to keep deleting one election from touching another's), the
  status and its dates (`opened_at`, `closed_at`, `published_at`, `archived_at`), the link to a first round, and in
  every slice votes, credentials and anything a voter produced.
- Slice 06a: every ballot is copied (title, description, `seats`, `allow_blank`, `position`) in the same transaction;
  each copy has its own new UUID. Slice 06b: every party is copied the same way (logo excluded: files are not shared). Slice 06c adds an optional `copy_candidates` flag, and
  slice 07 the voters; their endpoint files add the options then.

## Notes

The copy keeps the dates of the source, which are usually in the past for a finished election: the person edits them
before scheduling.
