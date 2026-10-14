# Slice 19 review (2026-10-13)

Reviewers: correctness, security (one pass each). Both read the diff against the brief, the command file and the endpoint files.

## Correctness
| # | Finding | Result |
|---|---|---|
| 1 | The 19b run narrowed the CSP violation test (ignores `/_next/static/` sources) | Kept on Jun's choice (dev server only); production build to check by hand in slice 20 |
| 2 | Platform screen has no state for `two_factor_required` | Fixed: explanation and two-factor card |
| 3 | `vote-audit:read` crashes on a large election (65 535 placeholders), use not logged | Fixed: chunks of 1 000, outcome `error` logged |
| 4 | The proxy overwrites the API's `Referrer-Policy` | Fixed: `?Referrer-Policy` in both Caddyfiles |
| 5 | Public results answer no longer matches its documented `Cache-Control` | Fixed in the endpoint file |
| 6 | `style-src-attr` not in the brief | Added to the brief |
| 7 | A log write failure is silent | Fixed: log before CSV, exit 1 if it fails |
| 8 | No log line when the CSV cannot be written | Fixed (log comes first) |
| 9 | A wrong key on an election with no vote is not detected | Noted: nothing to open, outcome `read` |
| 10 | The key stays in memory | Fixed: `sodium_memzero` |

## Security
| # | Finding | Result |
|---|---|---|
| 1 | A read can leave a decrypted CSV with no log line (`--log=/dev/null`, a read-only folder, a database error) | Fixed with correctness 3, 7, 8 |
| 2 | `audit_reader` falls back to the migrator account; no SELECT-only account is documented | Fixed: fallback only on `local` and `testing`; the six tables are written in the command file |
| 3 | The platform two-factor rule switched itself off on an unrecognised value in production | Fixed: `FILTER_NULL_ON_FAILURE`, production default on |
| 4 | The key is shown in stack traces when `exception_ignore_args` is off | Fixed: `#[SensitiveParameter]`, key wiped |
| 5 | `--key-stdin` does not work with piped input | Fixed |
| 6 | The production check is the only guard against running on a server | Accepted: the private key is still needed |
| 7 | The proxy replaces the API's `Referrer-Policy` | Fixed with correctness 4 |
| 8 | `style-src-attr 'unsafe-inline'` goes beyond the brief | Accepted and written into the brief |

## Id leak
Not run as its own pass: the slice adds no resource and no route; the CSV holds uuids only and the log line none of the
voter, vote or candidate.
