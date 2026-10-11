# DELETE /api/v1/vote/{election}/session

Ends the voter session: "this is not me", "next voter", or leaving.

| | |
|---|---|
| Slice | 12 |
| Requirements | FR-VOTE-06 |
| Caller | The voter session |
| Rate limit | The shared API limit |

## Scenarios

| # | Scenario | Status | `error.code` | Test |
|---|---|---|---|---|
| 1 | A valid session | 204; the cookie is cleared and the session forgotten | | |
| 2 | No session, an expired one or another election's | 204 (nothing to end, the same answer) | | |
| 3 | A session whose voter voted | 204 | | |
| 4 | Another method | 405 | `method_not_allowed` | |

Ending a session never changes a vote or a participation. A session that was ended cannot be used again.
