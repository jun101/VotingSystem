# VoteSystem — Delivery plan

Version 1.0 · 2026-10-05 · goes with [SPEC.md](SPEC.md) 1.1 and the mockups in
[design/mockups/](design/mockups/).

## 1. How the work is cut

The work is cut into **vertical slices**. A vertical slice is one small feature built
all the way through: database, API, screen, and tests. At the end of a slice a person
can do something new in the browser. The opposite, a horizontal layer ("all the
database first, then all the API, then all the screens"), shows nothing working until
the very end and hides integration bugs until then.

Slices are grouped into **milestones**. A milestone is a point where a whole part of
the product is usable (an institution can sign up; an election can be set up; people
can vote).

| Word | Meaning here | Count |
|---|---|---|
| Milestone | A usable part of the product | 7 |
| Slice | One feature, end to end, one pull request | 21 |
| Task | A step inside a slice (migration, endpoint, component) | decided by Sonnet in its slice plan |

## 2. The loop for every slice

| # | Step | Who | Output |
|---|---|---|---|
| 1 | **Brief** | Opus 5.5 | `docs/slices/NN-name.md`: requirement IDs covered, screens, API endpoints, tables, what is out of scope, done criteria |
| 2 | **Acceptance tests** | Opus 5.5 | Failing tests committed on the slice branch: API feature tests (Pest) and browser tests (Playwright). They state the behaviour; they do not dictate the code |
| 3 | **Code** | Sonnet 5.5 | Implementation plus its own unit tests, until every acceptance test passes. Sonnet may not edit an acceptance test; if one looks wrong it stops and says why |
| 4 | **Review** | 3 subagents, in parallel | Findings from `correctness-reviewer`, `security-reviewer`, `modernity-reviewer`, posted on the pull request |
| 5 | **Fix** | Sonnet 5.5 | Findings fixed, or answered when it disagrees |
| 6 | **Verify** | Opus 5.5 | Runs the full test suite and the app, compares the screens with the mockups, writes a short report |
| 7 | **Checkpoint** | Jun | Tries the feature in the browser, approves, merges the pull request |

Rules that hold for every slice:

- One slice is one branch (`slice/NN-name`) and one pull request on GitHub. CI runs the
  linters and all tests on it.
- A slice starts from an up-to-date `main` and merges before the next one starts.
- Each step runs in its own session with the model named above. The session reads
  `CLAUDE.md`, the slice brief and the files the brief points to, nothing else is assumed.
- A slice is **done** when: its acceptance tests pass in CI, no reviewer finding is left
  open, the screens match the mockups in French and English, at desktop and phone
  width, and Jun has approved.
- If a slice turns out too big (more than about a day of Sonnet work or a pull request
  above about 1 500 changed lines), Opus splits it before coding continues.

## 3. Milestones and slices

Screen numbers refer to the mockup canvas: `A01`–`A16` are the admin page, `M1`–`M5` the
voter flow, `M-results` the public results.

### Milestone 0 — Foundations

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **00 Technical design** (Opus only, no code) | Read the architecture, the database schema, the API contract (OpenAPI), the front-end structure and the design tokens taken from the mockups. `CLAUDE.md` and the three review subagents exist. GitHub repository and CI are set up | SPEC §6, §7, §10 |
| **01 Walking skeleton** | Run `docker compose up` and open a page served by Next.js that shows a value read from MariaDB through the Laravel API. Linters, Pest and Playwright run in CI. Base components exist: button, input, card, pill, page shell | NFR-OPS-01, NFR-SEC-07 |

### Milestone 1 — An institution has an account

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **02 Sign-up and sign-in** | Register an institution, verify the email (seen in Mailpit), sign in, sign out, reset a password. Screen A01 | FR-INST-01, 04 (password part), NFR-SEC-02, 04, 05 |
| **03 Admin shell and tenant isolation** | See the side menu, top bar and an empty dashboard; switch French/English. A second institution cannot read the first one's data (tested) | FR-INST-05, FR-NAV-02, 03, NFR-SEC-03, NFR-UX-01 |
| **04 Profile and users** | Edit the profile and logo, invite a manager, remove a user, turn on two-factor authentication. Screen A14 | FR-INST-02, 03, 04, FR-CAND-04 (image handling) |

### Milestone 2 — An election can be set up

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **05 Elections** | Create, edit, duplicate and delete a draft election; see all elections as cards with filters. Screens A03, A04 | FR-ELEC-01, 02, 03 (Draft), 06, 07 |
| **06 Ballots, parties, candidates** | Add ballots with seats and blank vote, parties, candidates with photo or default avatar, reorder them. Screens A06, A07 | FR-BAL-01, 02, 04, FR-CAND-01–04 |
| **07 Groups and voters** | Add a voter by hand, browse voters by group, open a voter's record, edit, delete, manage groups. Screen A08 | FR-VOT-01, 02, 06, 08 |
| **08 Excel import** | Download the template, upload a file, map columns, see the lines in error, import the valid ones, download the rejected ones. Screens A09, A10 | FR-VOT-03, 04, NFR-SEC-06, NFR-PERF-03 |
| **09 Reuse and scope** | Load voters from a past election with group renaming; restrict a ballot to groups; see the checklist and schedule the election. Screen A05 | FR-VOT-05, FR-BAL-03, 05, 06, FR-ELEC-03 (Scheduled), 04 |

### Milestone 3 — Voters get their codes

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **10 Codes and printed slips** | Generate codes for all, a group or one voter; download the US Letter PDF of slips with QR codes; reissue a lost code. Screen A11 | FR-CRED-01–06, 08 |
| **11 Email and export file** | Send the codes by email after confirming the count (seen in Mailpit); download the Excel/CSV file; see the status per group | FR-CRED-04, 05, 07, 08 |

### Milestone 4 — People vote

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **12 Voting flow** | Enter a code or follow a link, confirm identity, fill the eligible ballots, review, confirm; a second attempt is refused. Screens M1–M5 | FR-VOTE-01, 03–08, FR-SEC-01, 02, 06, 07, NFR-PERF-01, NFR-UX-02, 03 |
| **13 Opening and closing** | The election opens and closes by itself on time; a vote after the end is refused even if the scheduler is late; extend or close early; countdown and closed screens | FR-ELEC-03 (Open, Closed), 05, FR-VOTE-02, NFR-OPS-03 |

### Milestone 5 — Results

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **14 Turnout and dashboard** | Follow turnout live, in total, per day and per group; see who has not voted; no vote count is exposed. Screens A02, A12 | FR-RES-01, FR-SEC-03, 04, FR-VOT-07, FR-NAV-01 |
| **15 Results** | After closing, see counts, winners, ties and the bulletin check; write the public note; create a second round. Screen A13 | FR-RES-02, 03, 09, FR-SEC-05 |
| **16 Publication** | Publish and unpublish; open the public results page and the institution's public page without signing in; share with a correct preview image; download the PDF report and the social image. Screen M-results | FR-RES-04–08, FR-ELEC-03 (Published, Archived) |

### Milestone 6 — Trust and operations

| Slice | What a person can do at the end | Covers |
|---|---|---|
| **17 Audit log** | Read, filter and export every action of the institution's users. Screen A15 | FR-AUD-01–03 |
| **18 Platform administration** | As platform admin, list institutions, suspend and reactivate one; a suspended institution cannot sign in and its open votes stop. Screen A16 | FR-ADM-01, 02, FR-INST-06 |
| **19 Security hardening** | Pass the security checklist: headers and CSP, rate limits, upload checks, logs without secrets, dependency audit | NFR-SEC-01, 04, 05, 06, NFR-OPS-04 |
| **20 Load, backup, production** | 500 voters vote within a minute with no lost vote; a backup is restored following the written procedure; the production compose file runs on one VPS | NFR-PERF-02, NFR-OPS-02, SPEC §8 |

## 4. Order and dependencies

Slices run in number order. The only real dependencies are:

- 03 before everything in the admin area (shell and tenant isolation).
- 07 before 08 and 09 (voters exist before import and reuse).
- 09 before 12 (eligibility decides which ballots a voter sees).
- 10 before 12 (a voter needs a code).
- 12 before 13, 14, 15.

Slice 12 is the one where a mistake costs the most (ballot secrecy, one vote per voter).
It gets the largest set of acceptance tests and a second pass by `security-reviewer`.

## 5. What Jun provides

| When | What |
|---|---|
| Before slice 00 | The product name; the GitHub account or organisation and the repository name |
| Before slice 11 | Nothing for development (Mailpit catches emails). For production: the email provider |
| Before slice 20 | The VPS and the domain name. Any connection to the server is described and approved first |
| Every slice | About 15 minutes at the checkpoint to try the feature and approve |

## 6. Traceability

Every acceptance test names the requirement ID it checks. After slice 20, Opus produces
a table of every `FR-` and `NFR-` ID in the spec with the test that covers it; an ID
without a test is a gap to close before release.

## 7. Status

Updated 2026-10-05, end of the first session.

**Done and approved by Jun**

- Specification 1.1 ([SPEC.md](SPEC.md)).
- Mockups: voter flow on phone, voter flow on desktop, 16 admin screens, public results.
  Sources in [design/mockups/](design/mockups/); canvas at
  https://claude.ai/artifact/5stZZFJrHmxobGmEiep4KX
- This plan: vertical slices, the seven-step loop, Opus writes acceptance tests first,
  GitHub with one pull request per slice, a checkpoint with Jun after each slice,
  US Letter slips.

**Next: slice 00, technical design (Opus 5.5, no application code)**

Outputs: `docs/design/architecture.md`, `docs/design/database.md`,
`docs/design/api.yaml` (OpenAPI), `docs/design/frontend.md` (structure and design tokens
taken from the mockups), `CLAUDE.md`, the three review subagents in `.claude/agents/`,
the git repository and CI.

**Waiting on Jun before slice 00 starts**

1. The product name ("VoteSystem" is a placeholder in the mockups).
2. The GitHub account or organisation and the repository name. Nothing is created or
   pushed before he says so. The folder is not a git repository yet.
3. How Sonnet 5.5 is brought in for coding: launched as a subagent from the Opus session
   (recommended) or a separate session he opens with the model set to Sonnet.
4. Reviewer models: correctness and security on Opus 5.5, modernity on Sonnet 5.5
   (recommended, not yet confirmed).
5. Whether the desktop voting screens are approved as shown, and whether the QR scan
   button should also appear on desktop.
