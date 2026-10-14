# Slice 11 review (2026-10-11)

One round, two reviewers (correctness, security); Jun was asleep and had chosen two reviewers and everything local.

## Security
| # | Finding | Result |
|---|---|---|
| 1 | An unverified account could send up to 10 000 emails per run from the platform's domain with free-text names | Fixed: verified user email required (403), quota per institution per 24 hours (409) |
| 2 | One platform-wide throttle shared by institutions; giving up by time waiting | Fixed: per-institution rate inside a platform cap, attempts counted, backoff |
| 3 | Queued emails kept going after the institution was suspended | Fixed: the job checks the suspension |
| 4 | A printed reissue left the old email status | Fixed: a code written without email clears the status |
| 5 | Line breaks in free text could forge lines of the text email | Fixed: control characters become spaces |
| 6 | The job decided who gets an email when it ran | Fixed: a keyed hash of the address is recorded with the code; a changed address is `failed` |
| 7 | A status could stay `queued` with no job behind it | Fixed: the purge fails them after 7 hours; the crash paths mark them |
| 8 | Persistent error: retries without a pause | Fixed: backoff and release with a delay |
| 9 | Retry counter keyed by voter only | Fixed: voter and code hash |
| 10 | A partial file left if the save failed | Fixed: removed on failure |

## Correctness
| # | Finding | Result |
|---|---|---|
| 1, 2 | Statuses stuck `queued` after a paper reissue or a crash, locking the screen | Fixed with security 4 and 7 |
| 3 | The screen locked while emails were queued | Fixed: `running` follows queued files only; emails refresh more slowly |
| 4 | The result counted the code file as voters and reported its failure as voters with no code | Fixed: slips only; its own message |
| 5 | Acceptance tests edited in the coding commit | Confirmed: edits by the test author, listed in the summary |
| 6 | A message could be sent twice | Fixed: atomic claim, at most once |
| 7 | Failure counter keyed by voter | Fixed with security 9 |
| 8 | Files of a run matched by `created_at` | Fixed: a run identifier |
| 9 | `email=none` filter and the resend offer disagreed | Fixed: `email_failed` counts voters with an address; documented |
| 10 | No way to email a lost slip from the screen | Fixed: `lost-email` |
| 11 | "1 envoyés" | Fixed: plural messages |
| 12 | The ungrouped file named like all groups | Fixed: `codes-sans-groupe` |

## Left
- At-most-once delivery: a worker that dies right after the claim leaves the message `queued` until the 7-hour sweep marks it `failed`; a resend replaces the code.
- A hash of the address, not the address, is kept with the code: a changed address is detected, the old one is not recoverable.
- `composer audit` in CI, pinning of the `tc-lib` packages (slice 10 note).
