# Slice 10 review (2026-10-11)

One round, two reviewers (correctness, security); Jun was asleep and had chosen two reviewers and everything local.

## Security
| # | Finding | Result |
|---|---|---|
| 1 | One 80 s job for the whole run: an election above about 7 000 voters could not finish, and a timeout left every file `queued` for a day | Fixed: one job per file (120 s, `retry_after` 180), `failed()` marks the file in a fresh transaction, a `queued` file older than 15 minutes is failed when a run starts; test |
| 2 | Deleting a draft election left its encrypted files on disk | Fixed: stored copies removed in the destroy path; test |
| 3 | The election row was locked for the whole PDF build | Fixed: lock for the codes and for the ready mark only, PDF built outside; compensation removes only the credentials the file wrote |
| 4 | A generation failure left no trace; an empty hash key only fails on first use | Fixed: the exception class is logged (no message, no trace). Boot-time refusal left out: CI and some containers run without the key |
| 5 | A failed `realpath` would widen TCPDF's readable path | Fixed: the writer throws |
| 6 | TCPDF 7 and 14 `tc-lib` packages are a day old, no `composer audit` | Left: add `composer audit` to CI and pin minors later |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | Large `all` run cannot finish in one job | Fixed with security 1 |
| 2 | A new voter's slip could not be downloaded when an older, larger file existed | Fixed: A11 lists every ready file of the group, newest first; test |
| 3 | Parts of a split group grouped by an equal timestamp | Fixed: no timestamp grouping, parts labelled "i sur n" |
| 4 | The run endpoint's body was missing from the generated contract | Fixed: `scope` and `group` in `rules()`, cast removed from the web client |
| 5 | No test for the collision redraw, a voter served meanwhile, one failing file | Added in `api/tests/Feature/SlipFileJobTest.php` (5 tests) |
| 6 | Acceptance tests edited after the test commit | Confirmed: by the test author, listed in the summary |
| 7 | A file could end `ready` with no slip | Fixed: the row is removed when no voter is left |
| 8 | A failed part shifts the voters of the next part | Accepted, written in the endpoint file |
| 9 | `expires_at` frozen at creation | Fixed: the election's current end is shown; test |

## Known limitation
If a worker dies during phase 2 or 3, the file is marked failed but the codes it wrote cannot be told apart: those voters keep a code with no slip and a later run skips them. A lost-slip reissue is the remedy.
