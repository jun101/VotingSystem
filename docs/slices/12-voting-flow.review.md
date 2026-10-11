# Slice 12 review (2026-10-12)

Reviewers: correctness, security (two passes), id leak. Jun was asleep (full autonomy, local work and PR only).

## Security, pass 1
| # | Finding | Result |
|---|---|---|
| 1 | The per-election 300 per hour limit also refuses valid codes: an outsider can stop an election for an hour | Kept: it is the strict decision Jun made; **open for Jun** |
| 2 | No limit on session creation by valid codes | Fixed: public limiter on session and submit |
| 3 | Limit checked then counted, not atomic | Fixed: the increment decides, valid codes given back |
| 4 | IPv6 address rotation inside a /64 | Fixed: keyed on the /64 |
| 5 | Limiter key case-sensitive on the election uuid | Fixed: lower case |
| 6 | No length limit on `code` | Fixed: 64 characters, 422 |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | No test of real concurrency | Fixed: eight processes submit at once, one vote |
| 2 | A failed-email run replaced the code of a voter who voted | Fixed: run and preview skip voters with a participation |
| 3 | The language choice stayed on a shared computer | Fixed: session cookie scoped to the election, cleared on next voter |
| 4 | Deadlock between a vote and an admin edit of the voter | Fixed: the edit retries |
| 5 | Another session of a voter still served ballots after the vote | Fixed: 409 `already_voted` |
| 6 | JSON guard accepted a look-alike content type | Fixed: exact MIME type |
| 7 | Per-election lockout | Same as security 1 |
| 8 | Back button after the vote | Fixed: replace, not push |

## Id leak
No finding.

## Security, pass 2
| # | Finding | Result |
|---|---|---|
| 1 | Submit limit not atomic | Fixed |
| 2 | Malformed-body check ran before the header was normalised | Fixed: order swapped |
| 3 | Cookie name without `__Secure-` prefix | Left: the path `/api/v1/vote` rules out `__Host-`; planted cookie only shows a live session of a valid code |
| 4 | Parallel sessions for one valid code fill the cache | Left: records hold no secret; bounded by the public limiter |

## Left
- Decision for Jun: whether a valid code may pass the per-election limit.
- `__Secure-` cookie prefix and one live session per voter (optional hardening).
- The `data-nimg` test (see summary).
