# Slice 10 — Codes and printed slips

Brief (draft) · 2026-10-10 · branch `slice/10-codes-and-slips` (from `staging`)

## Goal

1. Access codes can be generated for all voters, for one group or for the voters with none yet, and reissued for one
   voter (FR-CRED-01, 02, 03).
2. Each generation writes the codes **and** the slips in one job: a US Letter PDF per group, 10 slips per page, cut
   lines, a QR code that opens the voting address with the code in the fragment (FR-CRED-04 printed slips, 05, 06).
3. Screen A11 shows the state per group and gives the PDFs until the election closes.

## Requirements covered

FR-CRED-01, 02, 03, 04 (printed slips only), 05, 06; FR-CRED-08 only as log lines (see below); FR-SEC-06, NFR-SEC-04,
NFR-SEC-05, NFR-UX-02, NFR-UX-03. Not here: email and the export file (slice 11), the voting page itself and the
"has voted" refusal data (slice 12), the audit log screen (slice 17).

## Jun's decisions (2026-10-10)

- PDF library: **TCPDF** (pure PHP, QR and Unicode built in, no browser engine).
- Codes can be generated and reissued in **draft, scheduled and open** elections; refused once closed, published or
  archived (409 `election_codes_locked`).
- One run makes **one PDF per group** (the voters with no group get their own file, "Sans groupe"), so each teacher
  downloads their class.

## Rules (to be written in the endpoint files)

| Rule | Value |
|---|---|
| Code | 10 characters from `ABCDEFGHJKMNPQRSTUVWXYZ23456789` (no `0 O 1 I L`), shown `XXXXX-XXXXX`; from a CSPRNG; about 49 bits |
| Stored | Only `code_hash` = HMAC-SHA-256 of the code (upper case, no hyphen) with `CREDENTIAL_HASH_KEY`; unique with the election; a collision draws again |
| Never | Written to a log, an error message, a job payload, a queue name, or sent in a URL; the code is in the job's memory, the encrypted file and (reissue only) one response body |
| Who | Owner and manager (as voters) |
| Generate | Voters of the scope **with no code yet** get one; those who have one are skipped and counted. Scope: `all`, `group` (a group of this election) or `ungrouped` |
| Files | One `generated_files` row and one PDF per group in the scope; at most 1 000 slips per file (a bigger group is split in parts, named "part 1 of 3") |
| Job | Queue job writes the codes and the PDFs together, groups one after another, each group in one transaction with the election row locked first; a failed file leaves its codes **unissued** (rolled back), never a code that no file shows |
| Reissue | One voter: a new code replaces the old hash, `reissue_count` + 1; answer 201 with the code shown **once** and a one-slip PDF; refused when the voter has voted (slice 12 supplies the data; the check and its test are added there) |
| Slip | Institution name, election title, voter name, group, code, voting address `{APP_URL}/vote/{election}#CODE`, QR of that address, voting period in the election's time zone; sorted by group then name; page break per group |
| Storage | Private disk, encrypted with the application key, under a random UUID; downloadable by owner and manager until the election closes, then deleted (command `files:purge`, daily) |
| Download | `GET /generated-files/{file}/download` streams the decrypted PDF, `Content-Disposition` with a fixed name (`coupons-{group-slug}.pdf`), `Cache-Control: no-store`; 404 for another institution's file |
| Limits | Generation 20 an hour per user, reissue 60 an hour, download 120 an hour; one run at a time per election (409 `run_in_progress`) |
| Logs | Outcome and counts only (FR-CRED-08 lines); the `audit_entries` table comes in slice 17, which then records these actions |

## Endpoints (new files in `docs/api/credentials/`)

| Endpoint | Purpose |
|---|---|
| `GET /elections/{election}/credentials` | State for A11: totals and, per group, voters, with code, with email, file status |
| `POST /elections/{election}/credential-runs` | Start a run `{scope, group}` → 202 with the files queued |
| `GET /elections/{election}/generated-files` | The election's files with status and size |
| `GET /generated-files/{file}` | One file's status (polling) |
| `GET /generated-files/{file}/download` | The PDF |
| `POST /voters/{voter}/credential` | Reissue: new code once, one-slip file |

## What to build (three runs, each ending green)

- **10a, codes and state:** migrations (`credentials`, `generated_files`), code generator and hasher, the credentials
  state and run endpoints, the job with a writer interface, reissue, tenant isolation entries.
- **10b, slips:** TCPDF slip writer with the QR, encryption and storage, file status, download, purge command.
- **10c, screen A11:** page `/admin/elections/{election}/codes` (cards per group, generate, download, reissue dialog
  with the code shown once and a print/download), the "Codes de vote" placeholder of the voters page becomes a link.

## Contract details the tests rely on

- Private disk `credentials` (`config('filesystems.disks.credentials')`, root `CREDENTIALS_ROOT`, default
  `storage/app/private/credentials`): the tests point its root at a temporary folder.
- A stored file is the PDF bytes passed through `Crypt::encryptString`, saved under `stored_file` (a UUID, no extension).
- The PDF is US Letter (`/MediaBox [0 0 612 792]`), 10 slips per page, uses core fonts so the text can be read back, and
  writes **each code as one string** `XXXXX-XXXXX`. In the page content a slip is written in this order: institution
  name, election title, voter name, group name (`Sans groupe` when none), code, voting address
  (`{APP_URL}/vote/{election uuid}`, no code in it), voting period. The QR code is drawn as vectors.
- The code hash is `hash_hmac('sha256', strtoupper(str_replace('-', '', $code)), CREDENTIAL_HASH_KEY, true)`.
- A write failure on the `credentials` disk marks the file `failed` and rolls the codes of that file back.

## Tests (written before the code, the coder never edits them)

- API: `api/tests/Acceptance/Slice10/` — `CredentialsStateTest`, `CredentialRunTest`, `CodeRulesTest`,
  `GeneratedFilesTest`, `DownloadFileTest`, `ReissueCredentialTest`; helpers in `api/tests/Support/Accounts.php`
  (`plantCredential`, `credentialRow`, `credentialRows`, `plantGeneratedFile`, `generatedFileRows`) and
  `api/tests/Support/Pdfs.php`; six routes in `Tenancy::TENANT_ROUTES`; the `Slice10` hook in `api/tests/Pest.php`.
- Browser: `web/e2e/slice10/codes.spec.ts`, helpers `web/e2e/support/credentials.ts`.

### Test ids of A11 (`/admin/elections/{election}/codes`)

| Element | Test id |
|---|---|
| Page, totals | `codes-page`, `codes-total-voters`, `codes-total-with-code`, `codes-total-without-code` |
| Run | `codes-generate-all` (the voters without a code), `codes-run-status` (shown while a file is `queued`), `codes-run-result` (after it) |
| One group, `{key}` is the group's UUID, or `none` for the voters with no group | `codes-group-{key}`, `codes-group-{key}-count` ("with a code" number), `codes-group-{key}-generate`, `codes-group-{key}-download` (first ready file; absent when there is none) |
| Lost slip | `codes-lost` (opens the dialog), `lost-search`, `lost-voter-{n}` (n from 1, matches of the search), `lost-confirm`, `lost-code` (the code, shown once), `lost-download` (the one-slip PDF), `lost-close` |
| Locked | `codes-locked-notice` (election closed, published or archived: no generate or lost button) |

Download names are those of the endpoint file. Text in French and English only; tokens only; phone: one column.

## Open for the endpoint files

- Whether the QR is SVG drawn into the PDF (vector) — yes unless TCPDF's barcode output is not scannable at 22 mm.
- 1 000 slips per file: confirmed by measuring a TCPDF run in the `queue` container (memory and time).
