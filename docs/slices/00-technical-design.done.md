# Slice 00 — Technical design: what was done

2026-10-06 · no application code in this slice.

## Written

| Document | What it settles |
|---|---|
| [architecture.md](../design/architecture.md) | One origin behind one proxy; the eight services; the layers of the API; the four kinds of caller; tenant isolation in three layers; the voting flow and the voter session; time and the scheduler; background jobs; secrets; logs; tests and CI |
| [database.md](../design/database.md) | 19 tables with columns, keys and delete rules; the vote transaction step by step; the encrypted audit record; two database accounts so the application cannot change or delete a vote |
| [frontend.md](../design/frontend.md) | The three areas and their budgets; every address with its mockup; folders; colours, type, shapes and breakpoints taken from the mockups; base components; accessibility and performance rules |
| [API conventions](../api/README.md) | Response and error shapes; the 17 status codes the API may return and when; the template every endpoint file follows |

Also: `.env.example` with the two keys the design needs, and `.gitignore` rules for
secret files.

## Changes to the specification (version 1.2)

- An encrypted audit record links each vote to its voter and time (FR-SEC-08, FR-SEC-09,
  NFR-SEC-09). The secrecy goal now reads "not through the application".
- Every identifier that leaves the server is a random UUID; public addresses no longer
  use slugs (NFR-SEC-08, FR-RES-04, FR-RES-07).

## Decisions taken in this slice

| Subject | Decision | Why |
|---|---|---|
| Votes | `votes` (one paper) and `vote_choices` (the candidates ticked) | With several seats, one row per paper keeps the integrity check a simple count |
| Keys of the vote tables | Random UUID only, no number, no timestamp | Either would line up with the list of who voted |
| Tenant column | `institution_id` on every tenant table | One filter per query |
| Another institution's record | Answers 404, never 403 | 403 would confirm it exists |
| Voter session | Its own cookie, limited to the voting paths, 15 minutes, kept in Redis | Separate from the admin session |
| Progress of background jobs | Polling, no WebSocket server | One less service on a small host |
| Voting flow controls | Native HTML only | Weight and accessibility |
| Fonts | Served from our own origin | Privacy, strict CSP, weight |

## Not done, and why

- **CI workflow file.** There is nothing to run yet. It is created in slice 01; its jobs
  are listed in architecture.md section 11.
- **Exact versions and some libraries** (spreadsheets, PDF, QR codes, images). Chosen at
  the slice that needs them, against what is current then (architecture.md sections 2
  and 13).

## Open

- How long the vote audit records are kept.
- Single audit key on the server, or a public/private pair with the private key kept off
  the server.

## To check at the checkpoint

Read the four documents. The parts most worth your time: database.md sections 2.4 and 4
(the vote), and the status code table in the API conventions.
