# Slice 11 — Email and the self-distribution file

Brief · 2026-10-11 · branch `slice/11-email-and-file` (from `slice/10-codes-and-slips` until slice 10 is merged)

## Goal

1. A generation run can also **email** each voter their personal link and code, after the person has seen and
   confirmed the number of messages (FR-CRED-04 email, FR-CRED-07).
2. A run can also write the **self-distribution file** (Excel or CSV: name, group, code, personal link) for an
   institution that uses its own channel (FR-CRED-04 file, FR-CRED-05).
3. The status of each voter's email is kept and shown: queued, sent, failed; the failed ones can be given a new
   code and sent again (FR-CRED-04). Credential status and email status filter the voters list (FR-VOT-06).

## Requirements covered

FR-CRED-04 (email and file), FR-CRED-05, FR-CRED-06, FR-CRED-07, FR-CRED-08 (log lines only; entries in slice 17),
FR-VOT-06 (credential status filter), NFR-SEC-02, NFR-SEC-05, NFR-UX-02, NFR-UX-03.

## Jun's decisions (2026-10-11)

- **Channels are chosen when generating.** One run: the job that makes a code also writes the slip, the file and
  sends the email (the code is never stored, so it cannot be sent later). A voter who already has a code is not
  emailed by a run; they are reissued one by one (the reissue can email too).
- Review: two reviewers (correctness, security).

## Rules (to be written in the endpoint files)

| Rule | Value |
|---|---|
| Preview | `POST /elections/{election}/credential-previews` `{scope, group?}` tells how many voters would get a code, how many have an email, how many have none (listed, up to 100, "left for print") and how many already have a code (skipped). Reads only |
| Run | `POST credential-runs` takes `email` (boolean), `file` (`xlsx`, `csv` or null) and, with `email`, `confirm_emails`: the count the person saw. A count that is no longer the right one answers 409 `email_count_changed` with the new count, and nothing is done |
| Email | One message per voter who gets a new code in the run and has an address: institution name, election title, voter name, voting period, a button with the personal link `{APP_URL}/vote/{election uuid}#{CODE}` and the code in text; in the election's language; designed by `docs/design/email.md` and added to `MailDesignTest` |
| Queue | One queued job per message with an **encrypted payload** (as the invitation does), rate-limited by a job middleware (`credentials.email_per_minute`, default 120), 3 tries with backoff; a message that cannot be sent is `failed` with a short fixed reason (never the address or the transport text) |
| Status | `credentials.email_status` `queued`, `sent`, `failed` (and `email_sent_at`, `email_error` as a reason code); a voter with no address has `null` |
| Resend | `scope` `failed_email` gives a new code to the voters whose email failed and sends it (their old code stops working); also `POST /voters/{voter}/credential` takes `email: true` |
| File | One file per run (kind `codes_xlsx` or `codes_csv`), columns `full_name`, `group`, `code`, `link`, sorted by group then name; written by the same job as the codes, encrypted, same download and purge rules as slips; text cells go through `FormulaGuard`; the Excel file uses the theme header of the import template |
| Who | Owner and manager |
| When | Same statuses as slice 10 (draft, scheduled, open) |
| Limits | The run limiter of slice 10; previews 120 an hour |
| Logs | Counts and outcomes only: never an address, a name, a code, a link or a transport message |

## Endpoints (`docs/api/credentials/`, `docs/api/voters/`)

New: `POST /elections/{election}/credential-previews`.
Changed: `POST .../credential-runs` (channels, `confirm_emails`, scope `failed_email`, new error codes),
`GET .../credentials` (email counts), `POST /voters/{voter}/credential` (`email`), `GET .../generated-files` and the
download (file kinds, names), `GET /elections/{election}/voters` and the voter object (`has_code`, `email_status`,
filters `credential` and `email`).

## What to build (three runs, each ending green)

- **11a, email:** previews, run options and confirmation, the queued email job with its middleware and statuses, the
  email view and its design test, `failed_email` scope, reissue with `email`, voters filters.
- **11b, file:** the Excel and CSV writers (reuse the import template styling and `FormulaGuard`), file kinds in the
  list, download names.
- **11c, screen A11:** the channel choices on the generate action, the confirmation dialog with the count and the
  list of voters without an address, email status per group and per voter, resend, file download.

## Not in this slice

The voting page (12), SMS or WhatsApp, a bounce or delivery report from the mail provider, audit entries (17).
