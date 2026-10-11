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

## Contract details the tests rely on

- Config `credentials.email_tries` (default 3): the email job sends; on a transport error it releases itself with a
  backoff while `attempts() < tries`, and on the last attempt marks the voter `failed` with a short fixed reason code
  (lower case letters and underscores, for example `send_failed`) and returns normally, so the run is never broken by a
  mail error (the tests set it to 1 and an unreachable SMTP host).
- The email is a Blade view built as the others (`mail.action` layout), `lang` set to the election's language, a
  preheader, a title, the button with `{APP_URL}/vote/{election uuid}#{CODE}` and the code in the text part, the voter's
  name and the election's title; it names the institution, never a user of it.
- File headers: `Nom complet, Groupe, Code, Lien` (election in French) or `Full name, Group, Code, Link`.

## Tests (written before the code, the coder never edits them)

- API: `api/tests/Acceptance/Slice11/` — `CredentialPreviewTest`, `EmailRunTest`, `FileRunTest`,
  `VoterCredentialStatusTest`, `ReissueEmailTest`, `CredentialEmailDesignTest`; the `Slice11` hook in `api/tests/Pest.php`
  and one route in `Tenancy::TENANT_ROUTES`.
- Browser: `web/e2e/slice11/codes-email.spec.ts`.

### Test ids (A11 and the voters page)

| Element | Test id |
|---|---|
| Open the dialog | `codes-send` ("Générer et envoyer") |
| Dialog | `send-dialog`, `send-scope` (select: `all`, a group's UUID, `ungrouped`, `failed_email`), `send-email` (checkbox), `send-file` (select: empty, `xlsx`, `csv`) |
| Numbers read from the preview | `send-count-generate`, `send-count-email`, `send-count-no-email`, `send-count-skipped`, `send-no-email-list` (names, up to 100) |
| Confirm | `send-confirm` (disabled until the numbers are read; sends `confirm_emails` with the count shown) |
| Result and status | `codes-run-result`, `codes-run-emails`, `codes-total-email-sent`, `codes-total-email-failed`, `codes-group-{key}-email` (text with the three counts), `codes-resend-failed` (opens the dialog on scope `failed_email` with email checked; shown only when a message failed) |
| Files | `codes-file-{n}` (n from 1: the code files, Excel or CSV, newest first; a link that downloads) |
| Voters page | `voters-filter-credential` (select: empty, `issued`, `none`), `voters-filter-email` (select: empty, `queued`, `sent`, `failed`, `none`), per card `voter-code-{n}`, `voter-email-status-{n}` |

## Review round (2026-10-11)

Decided from the two reviewers while Jun was asleep (recorded for his return):

- **Who may email:** a user whose email is not verified cannot send emails (403 `email_not_verified`, on a run with `email` and on a reissue with `email`); a run without email is not affected. An institution may send at most `credentials.emails_per_institution_per_day` (default 5 000) emails in 24 hours: more answers 409 `email_quota_exceeded` with the number left in `error.count`.
- **Throttle and giving up:** the 120 a minute limit is kept per institution (key by institution) inside a platform cap, giving up is by real send attempts, not by time in the queue; the job has a backoff; the retry counter is keyed by voter and code hash.
- **Suspension:** the email job checks the institution is not suspended and otherwise marks the voter `failed` (`institution_suspended`).
- **Statuses never lie:** a code written without an email clears `email_status`, `email_sent_at` and `email_error`; `files:purge` marks a voter whose email stayed `queued` more than 7 hours `failed` (`send_failed`) so `failed_email` can serve it; a message is sent only if the status is still `queued` (atomic claim); a crash after phase 1 of a slip file leaves the voters to that sweep.
- **Who is emailed is fixed at the run:** the run records which voters are to be emailed and at which address; the job refuses an address that changed.
- **Text:** control characters of the voter name, election title and institution name become spaces in the text email.
- **A run has its own identifier** (`run` UUID column on `generated_files`): the files of a run are matched by it, not by `created_at`.
- **Web:** `running` is driven by queued files only (queued emails refresh the page more slowly and never disable the buttons); the run result counts slip files only and reports a failed code file with its own message; the lost-slip dialog gets `lost-email` (a checkbox, shown for a voter who has an address) that reissues with `email: true`; group email counts are pluralised.
- **Names and counts:** the file of the voters with no group downloads as `codes-sans-groupe.{xlsx|csv}`; `email_failed` counts only voters who still have an address (the ones a resend can serve).

## Not in this slice

The voting page (12), SMS or WhatsApp, a bounce or delivery report from the mail provider, audit entries (17).
