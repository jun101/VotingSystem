# New Voting System — Database design

Version 1.0 · 2026-10-06 · goes with [SPEC.md](../SPEC.md) 1.3 and
[architecture.md](architecture.md).

Database: MariaDB (current LTS), InnoDB, `utf8mb4`. All date-times are stored in UTC.

## 1. Rules for every table

| Rule | Detail |
|---|---|
| Two keys | `id` `BIGINT UNSIGNED AUTO_INCREMENT` is the primary key, used only for joins inside the server. `uuid` `UUID` (version 4, random, unique) is the only identifier that leaves the server (NFR-SEC-08). |
| Timestamps | `created_at` and `updated_at` on every table. |
| Tenant column | Every table owned by an institution carries `institution_id`, even when it could be reached through a parent. Tenant scoping is then one filter on one column (NFR-SEC-03). |
| Enumerations | Stored as `VARCHAR`, with the allowed values held in a PHP enum. No database `ENUM` type, so adding a value needs no table rebuild. |
| Foreign keys | Declared on every reference. `RESTRICT` unless the table below says otherwise. |
| Deleting | No soft deletes, except `users` (the audit log must still name a removed user). |

Three tables are exceptions to the first two rules, on purpose. `votes`, `vote_choices`
and `vote_audit` have **no numeric id and no timestamp**: either one would line up with
`participations` and show who cast which vote (FR-SEC-01, FR-SEC-08). Pivot tables have
no `uuid`.

## 2. Tables

Types are abbreviated: `str(n)` is `VARCHAR(n)`, `dt` is `DATETIME`, `fk` is a
`BIGINT UNSIGNED` foreign key. `id`, `uuid`, `created_at` and `updated_at` are not
repeated.

### 2.1 Accounts and tenant

**`institutions`** — the tenant and its profile (FR-INST-02, FR-INST-06).

| Column | Type | Notes |
|---|---|---|
| name | str(150) | |
| type | str(20) | `school`, `university`, `association`, `other` |
| logo_file | UUID, null | Name of the stored image (section 5) |
| description | str(500), null | |
| address | str(255), null | |
| city | str(100), null | |
| phone | str(30), null | |
| contact_email | str(255), null | |
| timezone | str(64) | Default `America/Port-au-Prince` |
| language | char(2) | `fr` (default) or `en` |
| suspended_at | dt, null | Set by the platform admin |

**`users`** — institution users and platform admins (section 3 of the spec).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk, null | Null only for a platform admin |
| role | str(20) | `platform_admin`, `owner`, `manager` |
| name | str(150) | |
| email | str(255) | Unique |
| password | str(255) | Argon2id (NFR-SEC-02) |
| email_verified_at | dt, null | |
| two_factor_secret | text, null | Encrypted |
| two_factor_recovery_codes | text, null | Encrypted |
| two_factor_confirmed_at | dt, null | |
| language | char(2) | |
| last_login_at | dt, null | |
| deleted_at | dt, null | A removed user; the email is freed by rewriting it on removal |

Check constraint: `institution_id IS NULL` exactly when `role = 'platform_admin'`.

**`invitations`** — pending invitations (FR-INST-03).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | Cascade |
| invited_by_user_id | fk | |
| email | str(255) | |
| role | str(20) | `owner` or `manager` |
| token_hash | binary(32) | Unique. The token itself is only in the email |
| expires_at | dt | |
| accepted_at | dt, null | |

**`email_verification_tokens`** — the link sent to verify an email (FR-INST-01).

| Column | Type | Notes |
|---|---|---|
| user_id | fk | Cascade. One live token per user: a new one replaces the old |
| token_hash | binary(32) | SHA-256 of the token. Unique. The token itself is only in the email |
| expires_at | dt | 24 hours after creation |

No `uuid`, no `updated_at`.

**`password_reset_tokens`** — the link sent to reset a password (FR-INST-04). Replaces the
framework's standard table, which is keyed by email and would put the email in the link.

| Column | Type | Notes |
|---|---|---|
| user_id | fk | Cascade. One live token per user: a new one replaces the old |
| token_hash | binary(32) | SHA-256 of the token. Unique. The token itself is only in the email |
| expires_at | dt | 60 minutes after creation |

No `uuid`, no `updated_at`.

### 2.2 Election setup

**`elections`** (FR-ELEC-01 to 07, FR-RES-08, FR-RES-09).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| parent_election_id | fk, null | The election a second round comes from. Set null on delete |
| title | str(200) | |
| description | text, null | |
| starts_at | dt | UTC |
| ends_at | dt | UTC. Check: `ends_at > starts_at` |
| timezone | str(64) | IANA name, for entry and display |
| language | char(2) | |
| status | str(15) | `draft`, `scheduled`, `open`, `closed`, `published`, `archived` |
| cover_file | UUID, null | |
| candidate_order | str(10) | `manual` or `shuffled` (FR-BAL-04) |
| results_display | str(10) | `full` or `winners` (FR-RES-08) |
| opened_at | dt, null | When it really opened |
| closed_at | dt, null | When it really closed |
| published_at | dt, null | |
| archived_at | dt, null | |

Indexes: `(institution_id, status)`, `(status, starts_at)`, `(status, ends_at)` for the
scheduler.

**`ballots`** (FR-BAL-01 to 06, FR-RES-03). Cascade from `elections`.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| title | str(200) | |
| description | text, null | |
| position | smallint | Display order |
| seats | tinyint | Default 1. Check: `seats >= 1` |
| allow_blank | bool | Default true |
| scope | str(10) | `general` or `group` |
| result_note | text, null | Public note on the result |

**`voter_groups`** (FR-VOT-08). Named `voter_groups` because `groups` is a reserved word
in SQL.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| name | str(100) | As typed |
| name_key | str(100) | Trimmed and lower-cased; `utf8mb4_bin`, so accents count. Unique with `election_id` |

**`ballot_voter_group`** — the groups a group ballot covers (FR-BAL-03). No `id`, no
`uuid`, no timestamps.

| Column | Type | Notes |
|---|---|---|
| ballot_id | fk | Cascade |
| voter_group_id | fk | Restrict: a group used by a ballot cannot be deleted |

Primary key: `(ballot_id, voter_group_id)`.

**`parties`** (FR-CAND-01). Cascade from `elections`.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| name | str(100) | |
| acronym | str(15), null | |
| colour | char(7) | `#RRGGBB` |
| logo_file | UUID, null | |

**`candidates`** (FR-CAND-02, 03).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | |
| ballot_id | fk | Cascade |
| party_id | fk, null | Set null on delete |
| first_name | str(80) | |
| last_name | str(80) | |
| sex | str(6) | `male` or `female` |
| slogan | str(160), null | |
| biography | str(1000), null | |
| photo_file | UUID, null | |
| position | smallint | Display order |

### 2.3 Voters and codes

**`voters`** (FR-VOT-01, 06).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| voter_group_id | fk, null | Restrict: a group with voters cannot be deleted |
| full_name | str(150) | |
| identifier | str(50), null | Unique with `election_id` |
| email | str(255), null | Unique with `election_id` |
| phone | str(30), null | |

Index: `(election_id, voter_group_id, full_name)`.

**`credentials`** — the current code of a voter (FR-CRED-01 to 03). A reissue replaces the
hash in the same row.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | |
| voter_id | fk | Unique. Cascade |
| code_hash | binary(32) | HMAC-SHA-256 of the code with `CREDENTIAL_HASH_KEY`. Unique with `election_id` |
| issued_at | dt | |
| issued_by_user_id | fk, null | |
| reissue_count | smallint | Default 0 |
| email_status | str(10), null | `queued`, `sent`, `failed` |
| email_sent_at | dt, null | |
| email_error | str(255), null | |

**`voter_imports`** (FR-VOT-03, 04).

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| user_id | fk | |
| original_name | str(255) | Shown to the user only |
| stored_file | UUID | The uploaded file, private storage |
| mapping | json, null | Column of the file → voter field |
| status | str(15) | `uploaded`, `checked`, `importing`, `done`, `failed` |
| total_rows | int | |
| valid_rows | int | |
| invalid_rows | int | |
| processed_rows | int | For the progress bar |
| error_file | UUID, null | The rejected rows |
| finished_at | dt, null | |

**`generated_files`** — slip PDFs and code export files (FR-CRED-04, 05). Because only the
hash of a code is stored, the file is written by the same job that generates the codes.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk | |
| election_id | fk | Cascade |
| user_id | fk | |
| kind | str(15) | `slips_pdf`, `codes_xlsx`, `codes_csv` |
| voter_group_id | fk, null | Set when generated for one group. Set null on delete |
| voter_count | int | |
| status | str(10) | `queued`, `ready`, `failed` |
| stored_file | UUID, null | Encrypted, private storage |
| size_bytes | int, null | |
| expires_at | dt | The election's end; the file is deleted after it |

### 2.4 Voting: the secrecy boundary

These four tables are written together in one transaction (FR-SEC-02). Section 4
describes it.

**`participations`** — "this voter has voted" (FR-VOT-07, FR-SEC-01). No `uuid`, no
`updated_at`.

| Column | Type | Notes |
|---|---|---|
| election_id | fk | Restrict |
| voter_id | fk | Restrict: a voter who voted cannot be deleted |
| voted_at | dt | Exact time; shown to institution users |

Unique: `(election_id, voter_id)`. Index: `(election_id, voted_at)` for turnout over time.

**`votes`** — one anonymous ballot paper: what one voter put in for one ballot.

| Column | Type | Notes |
|---|---|---|
| uuid | UUID | Primary key. Version 4, random |
| election_id | fk | Restrict |
| ballot_id | fk | Restrict |

Nothing else: no numeric id, no voter, no timestamp. A voter's papers for different
ballots share nothing.

**`vote_choices`** — the candidates ticked on a paper. No rows for a paper means a blank
vote.

| Column | Type | Notes |
|---|---|---|
| vote_uuid | UUID | Cascade is not set; restrict |
| candidate_id | fk | Restrict |

Primary key: `(vote_uuid, candidate_id)`. Index: `(candidate_id)` for counting.

**`vote_audit`** — the encrypted link between a vote and its voter (FR-SEC-08, 09).

| Column | Type | Notes |
|---|---|---|
| uuid | UUID | Primary key. Version 4, random, unrelated to the vote's |
| election_id | fk | Restrict. In clear, to find an election's records |
| payload | varbinary(255) | Encrypted (section 4.2) |

Nothing else readable: no voter, no vote, no numeric id, no timestamp.

### 2.5 Trust

**`audit_entries`** (FR-AUD-01 to 03). No `updated_at`.

| Column | Type | Notes |
|---|---|---|
| institution_id | fk, null | Null for platform actions |
| user_id | fk, null | |
| actor_name | str(150) | Copied at write time |
| action | str(60) | For example `election.scheduled`, `credentials.reissued` |
| election_id | fk, null | Set null on delete |
| subject_type | str(40), null | |
| subject_uuid | UUID, null | |
| details | json, null | Never a code, a password or a vote |
| ip_address | str(45) | |

Index: `(institution_id, created_at)`.

### 2.6 Framework tables

`migrations`, `failed_jobs`, `job_batches`. Sessions, cache, queues and rate-limit
counters are in Redis and have no table.

## 3. What deleting does

| Deleting | Effect |
|---|---|
| A draft election | Its ballots, candidates, parties, groups, voters, credentials, imports and files go with it. |
| An election that has votes | Refused by the database: `participations`, `votes` and `vote_audit` restrict it (FR-SEC-06). |
| A voter who voted | Refused by the database. |
| A group used by a voter or a ballot | Refused by the database (FR-VOT-08). |
| An audit entry, a vote, a participation | Not possible for the application's database account (section 6). |

## 4. Recording a vote

### 4.1 The transaction (FR-VOTE-04, FR-SEC-02, FR-SEC-07)

1. Start a transaction.
2. Read the voter row with a lock (`SELECT … FOR UPDATE`). A second request for the same
   voter waits here.
3. Check the election is `open` and the server time is between `starts_at` and `ends_at`
   (FR-ELEC-05).
4. Check there is no `participations` row for this voter.
5. Work out the ballots the voter is eligible for (FR-BAL-05). The submission must hold
   exactly these ballots; each must hold candidates of that ballot, no more than `seats`,
   and none only if `allow_blank`.
6. Insert one `participations` row.
7. For each ballot insert one `votes` row, its `vote_choices` rows and one `vote_audit`
   row.
8. Commit. Any error at any step rolls everything back.

The unique key on `participations (election_id, voter_id)` is the last line of defence:
if two requests ever got past step 4 together, the second commit fails.

### 4.2 The audit payload

| Item | Choice |
|---|---|
| Content | A format version, the record's own `uuid`, the election's `uuid`, the vote's `uuid`, the voter's `uuid`, the ballot's `uuid`, the time to the second |
| Encoding | Fixed-length binary, so every payload has the same size |
| Cipher | A libsodium sealed box (X25519 and XSalsa20-Poly1305; libsodium is bundled with PHP). Each record is encrypted to the public key with a fresh one-time key |
| Public key | `VOTE_AUDIT_PUBLIC_KEY`, 32 bytes given as 64 hex characters in the server's environment. It can only encrypt |
| Private key | 32 bytes, held by the platform operator, never on the server |
| Bound to | The record's own `uuid` and the election's `uuid` are inside the payload; the reading tool refuses a payload found in another row or election |
| Stored as | The sealed box: 48 bytes more than the content |

The server cannot read what it has written. Reading is done only by the command-line
tool of FR-SEC-09, which is given the private key for one use.

### 4.3 What the database cannot hide

The design stops anyone with SQL access, a database dump or the admin interface from
linking a voter to a vote without the key. Two lower-level traces remain and are handled
by operations, not by the schema:

- **The binary log** records a transaction's writes together. Production runs with the
  binary log off, or kept for the shortest time the backup method needs.
- **The storage engine's internal transaction number** is the same for rows written in
  one transaction. It is not reachable by SQL; reading it needs the raw data files, that
  is, full control of the server.

Both need full control of the server. The private key is not there, so the audit records
themselves stay unreadable even then; these two traces are the reason the binary log
setting matters.

## 5. Stored files

Files are named by a random UUID; the original name is never used on disk.

| Kind | Where | Served |
|---|---|---|
| Logos, candidate photos, covers | Public storage, re-encoded to WebP in fixed sizes (FR-CAND-04) | Directly, at `/media/{uuid}-{size}.webp` |
| Uploaded spreadsheets, error files | Private storage | Only through a signed-in endpoint |
| Slip PDFs, code exports | Private storage, encrypted (FR-CRED-05) | Only through a signed-in endpoint, until the election closes |

## 6. Database accounts

| Account | Used by | Rights |
|---|---|---|
| `app` | The API, the queue worker, the scheduler | Read and write on all tables, **except**: no `UPDATE` or `DELETE` on `participations`, `votes`, `vote_choices`, `vote_audit`, `audit_entries` |
| `migrator` | Migrations and the maintenance commands run by the operator | All rights on the schema |

So a bug or an injection in the application cannot change or remove a vote, a
participation or an audit entry. Deleting a draft election still works, since a draft has
none of these rows.

## 7. Keeping the audit records

`vote_audit` rows are kept as long as their election exists (FR-SEC-10). No job deletes
them.
