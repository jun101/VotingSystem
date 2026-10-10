# Slice 07 review (2026-10-10)

One round, three reviewers: correctness, security, id-leak. No blocking finding.

## Id-leak
No leak found: resources output the UUID as `id`, bindings go through `uuid`, `?group=` and the merge `into` are looked up by UUID, no numeric id in the generated OpenAPI file, in headers, logs or HTML.

## Security
| # | Finding | Result |
|---|---|---|
| 1 | The email rule accepted `a@localhost`, IP-literal domains and quoted parts with line breaks | Fixed: `email:strict,filter`, three test cases |
| 2 | An open election could still get a new group, and a voter could change group | Fixed: contract changed (POST and PATCH voters), 409 `election_voters_locked`, tests; a voter may repeat their own group |
| 3 | A duplicate identifier could be reported as a duplicate email | Fixed: field chosen from the unique index name |
| 4 | Spreadsheet formulas stored as typed | Noted: the export slice must prefix such cells (NFR-SEC-06) |
| 5 | `?page=99999999` gave the error page | Fixed: page capped at 1 000 000 |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | Duplicate field from the SQL text | Same as security 3 |
| 2 | Two acceptance tests edited by the coding step | Approved by Jun in the session; same pattern as slice 06 |
| 3 | The page stayed in locked mode after a 409 | Fixed: closed rules hold only until the refreshed election arrives |
| 4 | The address kept `?page=99` after the server moved to the last page | Fixed: the page rewrites its address on load |
| 5 | Page above 1 000 000 | Same as security 5 |
| 6 | `name_key` too short for a lower-cased name (`İ`) | Fixed: migration `500003`, key widened to 200, test |
| 7 | Identifiers ignore accents as well as case | Decided by Jun: kept, written in the endpoint file |
| 8 | No test of deleting an election with voters in groups | Fixed: `DeleteElectionWithVotersTest` |

## Left for later
Parties have the same `name_key varchar(100)` lower-casing risk as groups had (slice 06 code).
