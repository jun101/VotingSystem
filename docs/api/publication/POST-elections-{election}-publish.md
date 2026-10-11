# POST /api/v1/elections/{election}/publish

Makes the results of a closed election public at `/results/{election}`.

| | |
|---|---|
| Slice | 16 |
| Requirements | FR-RES-04, FR-RES-08, FR-ELEC-03 |
| Caller | Institution user: owner or manager |
| Rate limit | 20 requests per hour per user (shared with unpublish, archive and unarchive) |

## Request

| Path parameter | Type | Rules |
|---|---|---|
| election | UUID | An election of the caller's institution that is `closed` |

Body, JSON, optional: `{ "results_display": "full" | "winners" }`. When given it replaces the election's setting before
publishing; when absent the election's own `results_display` is used.

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A closed election whose bulletin check passes on every ballot | 200 (status `published`, `published_at` now) | | |
| 2 | `results_display` given | 200 (the setting is changed and used) | | |
| 3 | A ballot ended in a tie | 200 (a tie is published as a tie, never broken) | | |
| 4 | The bulletin check fails on a ballot | 409 | `results_check_failed` (`error.ballots` lists the ballots' UUIDs) | |
| 5 | Not `closed` (draft, scheduled, open, published, archived) | 409 | `election_not_closed` | |
| 6 | `results_display` not `full` or `winners` | 422 | `validation_failed` (`results_display: invalid`) | |
| 7 | Not a UUID, unknown, or another institution's election | 404 | `not_found` | |
| 8 | Not signed in, suspended institution, CSRF, too many requests, another method, bad JSON | 401, 403, 419, 429, 405, 400 | the shared codes | |

## Responses

### 200

The election as in [GET election](../elections/GET-elections-{election}.md) with `status` `published`.

## Notes

The row is locked and the status read again under the lock; the bulletin check is computed in the same transaction.
Nothing about a vote is read other than the counts the check needs. After publication the voter page of the election
answers `results: "/results/{election}"` ([GET vote](../vote/GET-vote-{election}.md)).
