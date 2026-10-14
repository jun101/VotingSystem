# php artisan vote-audit:read {election}

Reads the sealed vote audit of one election and writes it as a CSV. The only way to read the audit (FR-SEC-09); run by
the platform operator **on their own machine**, never on the server.

| | |
|---|---|
| Slice | 19 |
| Requirements | FR-SEC-08, FR-SEC-09, FR-SEC-10, NFR-SEC-09 |
| Caller | The platform operator, on the command line, with the private key |

## Options

| Option | Rules |
|---|---|
| `election` (argument) | The election's UUID |
| `--reason=` | Required, 5 to 300 characters: why the audit is read |
| `--operator=` | Required: the operator's name, written to the log |
| `--key-file=` | Path of a file holding the private key as 64 hex characters (the secret key of the audit key pair); or `--key-stdin` to read it from standard input. Exactly one of the two. The key is never stored, printed or logged |
| `--out=` | Required: the CSV file to write; it must not exist; it is created with mode 0600 |
| `--log=` | The operator's log file (default `storage/logs/vote-audit-reads.log`); one JSON line is appended per use |

The connection used is `audit_reader` (config `database.connections.audit_reader`, from `DB_AUDIT_READER_*`): a
database user with SELECT only on `elections`, `ballots`, `voters`, `candidates`, `vote_choices` and `vote_audit`, on
the operator's side (a dump or a tunnel). Without `DB_AUDIT_READER_*` the command falls back to the migrator account
**only** when `APP_ENV` is `local` or `testing`; anywhere else it refuses (exit 1, nothing read).

## Behaviour

| # | Scenario | Result | Test |
|---|---|---|---|
| 1 | Valid election, reason, operator, right key | Exit 0; the CSV has a header and one row per vote: `record, election, ballot, ballot_title, voter, voter_name, candidates, voted_at` (UTC ISO 8601, `candidates` joined by `; `, empty for a blank vote); cells are protected by `FormulaGuard`; the log line is appended | |
| 2 | An election with no vote | Exit 0; header only; the log line says `records: 0` | |
| 3 | The key does not open the records (a wrong key) | Exit 1; no CSV file; the log line says `outcome: key_rejected` | |
| 4 | `--reason`, `--operator`, `--out` or the key source missing, a reason shorter than 5 characters, both key sources, a key that is not 64 hex characters | Exit 1; nothing read, no file | |
| 5 | The election does not exist, or the UUID is invalid | Exit 1; no file; the log line says `outcome: unknown_election` | |
| 6 | The `--out` file already exists | Exit 1; the file is left as it was | |
| 7 | `APP_ENV` is `production` | Exit 1: "never on the server"; nothing read | |
| 8 | Any use that gets as far as reading | The log line is appended **before** the CSV is opened and, if it cannot be appended, the command exits 1 with no CSV. An error while reading logs `outcome: error`. One JSON line in the log: `at` (UTC), `operator`, `election` (uuid), `reason`, `records`, `outcome` (`read`, `key_rejected`, `unknown_election`, `error`); **never** the key, a voter, a vote or a candidate | |

The command never writes to the database, never logs the CSV's content, and results are never computed from it.
