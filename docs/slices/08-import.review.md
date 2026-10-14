# Slice 08 review (2026-10-10)

One round, three reviewers: correctness, security, id-leak. No blocking finding.

## Id-leak
No leak found: resources output the UUID as `id` and `election` (never `*_id`), routes bind by `uuid`, files are stored under a random UUID with fixed download names, jobs carry only the import UUID, logs carry only an outcome.

## Security
| # | Finding | Result |
|---|---|---|
| 1 | The zip-bomb bound trusted the sizes the ZIP declares | Fixed: real inflated bytes counted by streaming each entry; test with a false declared size |
| 2 | No bound on lines or cells read (a huge sheet dimension kept the single worker busy) | Fixed: 11 000 lines read, empty ones included; rows cut to the header width; test |
| 3 | Worker temp files left behind when the job is killed | Fixed: per-import temp folder removed at the end, in `failed()` and by the purge |
| 4 | A small file became a lot of stored text, kept 7 days | Fixed: each value cut to its field's maximum plus one character |
| 5 | An import stuck in `importing` was never purged | Fixed: failed and purged after a day, under a lock |
| 6 | The uploaded file stayed after `done` | Fixed: deleted when done |
| 7 | Formula-like text is stored in `voters` | Shared `FormulaGuard` helper; later exports must use it |
| 8 | The header width was unbounded | Fixed: at most 50 columns, else failed |
| 9 | No test for hostile XML and ZIP | Added: false-size bomb, entity expansion, huge dimension |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | The 202 of the upload carried null counts | Fixed: counters are 0; test |
| 2 | `nothing_to_import` and `no_error_file` had no message | Fixed: French and English; test |
| 3 | The preview stayed empty after a re-mapping cleared every error | Fixed: filter back to all; browser test |
| 4 | A failed dispatch on PATCH left the import `uploaded` | Fixed: handled as in confirm |
| 5 | The purge could delete an import confirmed at the same moment | Fixed: re-read under a lock |
| 6 | CSV line numbers count records, not physical lines | Decided by Jun: kept, written in the endpoint file |
| 7 | PATCH answered 422 where the contract lists 409 for an import with no columns | Fixed: contract order changed (state before body); test |
| 8 | Two browser tests were edited after the test commit | A wait for the address was added by the verification step; no assertion weakened |
| 9 | No way back from A09 to a running import | Left: a later slice can add the current import to the 409 or the election |

## Decided by Jun
No dedicated queue for imports for now: the caps keep a check short. Revisit with slices 10 and 11 (codes, emails).

## Found by hand
The dev `api`, `queue` and `scheduler` containers must be recreated (`make up`) after the image gained the `zip` extension, or the template and error-file downloads answer 500 while the tests, which use a fresh container, pass. The download tests now check the content.
