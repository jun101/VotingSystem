# Slice 09 review (2026-10-10)

One round, three reviewers: correctness, security, id-leak. No blocking finding.

## Id-leak
No leak found: resources go through `ApiResource`; the pivot, `ballot_id` and `voter_group_id` are never output; checklist items carry UUIDs; `error.items` and logs carry no numeric id; the generated OpenAPI file adds no `*_id`.

## Security
| # | Finding | Result |
|---|---|---|
| 1 | `groups` has no cap before the query (70 000 UUIDs: 500 instead of 422) | Fixed: more than 100 items answers 422 before any query, in both requests; tests |
| 2 | Merging groups in a scheduled election changes who can vote on a locked group ballot | Decided by Jun: kept (ballots follow the groups); written in the merge endpoint file |
| 3 | Unschedule ignores the start time | Left for slice 13 (when opening is automatic, refuse after `starts_at` or open under the same lock) |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | The copy dialog's source list omitted archived elections | Fixed: archived elections are read too |
| 2 | Deleting a draft election with a group ballot could hit the restrict on the pivot | Fixed: the pivot rows are removed first; test |
| 3 | Race: `groups` written onto a ballot that became general | Fixed: nothing written when the locked ballot is general |
| 4 | Duplicating dropped the scope silently | Decided by Jun: copied as general, written in the duplicate endpoint file; test |
| 5 | French plural "1 électeur ne peuvent" | Fixed |
| 6 | `groups` judged for a general ballot (422 where the file says ignored) | Fixed; test |
| 7 | No retry after a failed checklist read, stale items shown | Fixed |
| 8 | Rename only in "some groups" mode | Fixed: also in "all" mode |
| 9 | Acceptance tests changed in coding commits | Confirmed: made by the test author |

## Also
The message `election_not_editable` was reworded: a scheduled election can be unscheduled.
