# New Voting System — Functional Specification

Version 1.3 (1.0 approved 2026-10-05; 1.1 adds what the approved mockups introduced;
1.2, 2026-10-06, adds the encrypted vote audit record and UUID public addresses;
1.3, 2026-10-06, encrypts the audit record with a key pair and settles how long it is kept)

Requirement IDs (`FR-…`, `NFR-…`) are stable. Design documents, code, reviews and tests
refer to them. Items marked **[ASSUMPTION]** are choices made without confirmation; they are
listed again in section 12.

---

## 1. Purpose

A web platform that lets an institution (first target: schools in Haiti) run its own
elections online: register, set up an election with one or more ballots, load the voters,
distribute credentials by email or on paper, collect votes secretly, and publish the
results on a public page that can be shared on social media.

### Goals

- An institution with no technical staff can run a full election alone.
- A voter can vote in under a minute on a cheap phone and a slow connection.
- Nobody can find out how a given voter voted through the application: not the
  institution, not a platform admin signed in to it. One encrypted audit record links
  each vote to its voter; only the platform operator, holding a private key that is
  never on the server, can read it (FR-SEC-08).
- Each voter votes at most once per election.
- Distributing credentials costs the institution nothing if it chooses paper.

### Out of scope for v1

- Payments, subscriptions, plan limits.
- Ranked-choice or weighted voting.
- SMS delivery and WhatsApp Business API delivery.
- Posting automatically to an institution's social media accounts (v1 gives a shareable
  public page and share buttons instead).
- Native mobile applications.
- Cryptographic end-to-end verifiable voting.

---

## 2. Glossary

| Term | Meaning |
|---|---|
| Institution | The organisation that runs elections (a school, an association). The tenant. |
| Institution user | A person who manages an institution in the admin area. |
| Platform admin | The operator of the platform itself. |
| Election | What the brief calls a "vote": one event with a start, an end and a timezone. |
| Ballot | One position to fill inside an election (President, Secretary, …). |
| Party | A group of candidates inside an election (a slate, a list). Optional. |
| Candidate | A person standing on one ballot. |
| Voter | A person allowed to vote in one election. |
| Group | A named set of voters inside an election, such as "4th grade" (the "description" field of the brief). A voter has at most one group. |
| General ballot | A ballot open to every voter of the election. |
| Group ballot | A ballot open only to the voters of the groups chosen by the institution. |
| Credential | The secret access code that lets one voter vote in one election. |
| Blank vote | A voter's explicit choice to select no candidate on a ballot. |

---

## 3. Roles

| Role | Signs in with | Can do |
|---|---|---|
| Platform admin | Email + password | List, suspend and reactivate institutions; see platform statistics. Cannot see votes or credentials in the application. |
| Institution owner | Email + password | Everything inside their institution, including managing other institution users and the profile. |
| Institution manager | Email + password | Manage elections, ballots, candidates, voters and credentials. Cannot manage users or delete the institution. |
| Voter | Credential only | Vote once in the election the credential belongs to. No account. |
| Public visitor | Nothing | View published results pages and the institution's public profile. |

---

## 4. Functional requirements

### 4.1 Institution account and profile

- **FR-INST-01** A person registers an institution with: institution name, their own name,
  email, password. The email must be verified before any election can be opened.
- **FR-INST-02** The profile holds: name, type (school, university, association, other),
  logo, short description, address, city, phone, contact email, default timezone
  (default `America/Port-au-Prince`), default language, and a public identifier (a
  random UUID) used in its public address.
- **FR-INST-03** The owner can invite other institution users by email with the role
  owner or manager, and can remove them. An institution always keeps at least one owner.
- **FR-INST-04** Password reset by email. Optional two-factor authentication (TOTP) for
  institution users.
- **FR-INST-05** Every piece of data belongs to exactly one institution. An institution
  user can never read or change another institution's data.
- **FR-INST-06** A suspended institution cannot sign in and its open elections stop
  accepting votes; its published results stay visible. **[ASSUMPTION]**

### 4.2 Elections

- **FR-ELEC-01** An election has: title, description, start date-time, end date-time,
  timezone (IANA name, default taken from the institution), language, optional cover image.
- **FR-ELEC-02** Times are entered and displayed in the election's timezone and stored in
  UTC. The end must be after the start.
- **FR-ELEC-03** Lifecycle:

  | Status | Entered when | What is allowed |
  |---|---|---|
  | Draft | Created | Everything can be edited. Can be deleted. |
  | Scheduled | Admin confirms the setup | Ballots and candidates are locked. Voters and credentials can still be managed. Can go back to Draft. |
  | Open | Start time reached (automatic) | Voting. Voters can be added and credentials reissued. End time can be extended, not shortened into the past. |
  | Closed | End time reached (automatic) or admin closes early | No voting. Results visible to institution users. |
  | Published | Admin publishes | Results visible to the public. Can be unpublished. |
  | Archived | Admin archives | Read-only, hidden from the default list. |

- **FR-ELEC-04** To move from Draft to Scheduled, the election needs at least one ballot,
  at least two candidates on each ballot (or one, with a warning), and at least one voter.
  The institution is warned about any group ballot with no eligible voter and any voter
  eligible for no ballot.
- **FR-ELEC-05** The server accepts a vote only if the election is Open **and** the server
  time is between start and end. The status alone is not trusted.
- **FR-ELEC-06** An election can be duplicated: ballots are copied; candidates and voters
  are optional in the copy; votes and credentials never are.
- **FR-ELEC-07** The institution sees the history of all its elections, with filters by
  status and year, and can open any past election's results.

### 4.3 Ballots

- **FR-BAL-01** An election has one or more ballots. A ballot has: title, description,
  display order, number of seats (default 1), whether a blank vote is allowed
  (default yes), and a scope (FR-BAL-03).
- **FR-BAL-02** The number of seats is 1 unless the institution chooses otherwise. On a
  ballot the voter selects at most "number of seats" candidates; with one seat this is a
  normal single choice.
- **FR-BAL-03** A ballot's scope is either **general** (every voter of the election, the
  default) or **group** (only the voters of one or more groups chosen by the
  institution). One election can mix both: a President elected by the whole school and a
  delegate elected by each class.
- **FR-BAL-05** A voter is eligible for all general ballots plus the group ballots that
  include their group. A voter without a group is eligible for general ballots only.
- **FR-BAL-06** When a group ballot covers fewer than 5 voters the institution is warned
  that the result may reveal how individuals voted. It is a warning, not a block.
- **FR-BAL-04** Candidate order on a ballot is either manual or shuffled per voter;
  the institution chooses per election.

### 4.4 Parties and candidates

- **FR-CAND-01** A party belongs to one election and has: name, acronym, colour, logo.
  Parties are optional.
- **FR-CAND-02** A candidate belongs to one ballot and has: first name, last name, sex
  (male, female), optional party, optional slogan, optional short biography, optional
  photo, display order.
- **FR-CAND-03** A candidate without a photo is shown with a default avatar chosen by sex.
- **FR-CAND-04** Photos and logos: JPEG, PNG or WebP, 5 MB maximum. The server re-encodes
  them and produces resized versions; the original file is never served.

### 4.5 Voters

- **FR-VOT-01** Voters belong to one election. A voter has: full name, optional group
  (e.g. "4th grade"), optional identifier given by the institution (student number),
  optional email, optional phone. No date of birth or other personal data is collected:
  many voters are minors.
- **FR-VOT-08** Groups belong to one election. They are created by hand, or automatically
  from the distinct values of the group column during an import (compared without regard
  to case and surrounding spaces). A group can be renamed, and merged into another. A
  group can be deleted only if no voter and no ballot uses it.
- **FR-VOT-02** Add one voter with a form.
- **FR-VOT-03** Import many voters from an `.xlsx` or `.csv` file:
  1. Download a template file.
  2. Upload; the system proposes a column mapping that the user can correct.
  3. Preview with per-row validation: missing name, invalid email, duplicate inside the
     file, duplicate of an existing voter (same identifier, or same email, or same name
     and group).
  4. Confirm; valid rows are imported, invalid rows are returned as a downloadable
     error file. Nothing is imported before confirmation.
- **FR-VOT-04** Import limit: 10 000 rows per file. Imports run in the background with a
  progress indicator.
- **FR-VOT-05** Load the voters of a previous election of the same institution, with
  their groups. The user can pick all voters or some groups, and can rename groups during
  the copy (last year's "4th grade" becomes "5th grade"). Credentials and participation
  are never copied.
- **FR-VOT-06** Search, filter by group, by credential status and by "has voted". Edit a
  voter. Delete a voter only if they have not voted.
- **FR-VOT-07** Institution users can see **whether** a voter has voted and when, never
  **what** they voted.

### 4.6 Credentials and distribution

The credential is one access code per voter per election: 10 characters from an alphabet
without look-alike characters (no `0 O 1 I L`), shown as `XXXXX-XXXXX`. About 49 bits of
randomness.

- **FR-CRED-01** Credentials are generated on demand for all voters, a group, or one voter.
- **FR-CRED-02** Only a hash of the code is stored. A code cannot be displayed again
  after it was produced; it can only be reissued.
- **FR-CRED-03** Reissuing invalidates the previous code. It is refused if the voter has
  already voted.
- **FR-CRED-04** The institution chooses a distribution channel per election, and can mix
  them:

  | Channel | How it works | Cost |
  |---|---|---|
  | **Printed slips** | PDF, US Letter (8.5 × 11 in), 10 slips per page with cut lines, sorted by group then name. Each slip: institution name, election title, voter name, group, code, voting address, QR code, voting period. One page break per group so each teacher receives their class. | Paper only |
  | **Email** | One message per voter with a personal voting link and the code in text. Sent through a queue, rate-limited. Status per voter: queued, sent, failed. Resend possible. | Per message |
  | **Self-distribution file** | CSV/XLSX with name, group, code and personal link, for an institution that wants to use its own channel (its own mail merge, WhatsApp by hand). | None |

- **FR-CRED-05** A generated PDF or distribution file is stored encrypted, can be
  downloaded by institution users until the election closes, and is then deleted.
- **FR-CRED-06** The personal link carries the code in the URL fragment
  (`/vote/{election-uuid}#CODE`) so the code is not written in server or proxy logs.
- **FR-CRED-07** Before sending emails, the system shows the number of messages that will
  be sent and asks for confirmation. Voters without an email are listed and left for print.
- **FR-CRED-08** Every generation, reissue, download and send is written to the audit log.

### 4.7 Voting

- **FR-VOTE-01** The voter opens the personal link or scans the QR code, or opens the
  election's voting address and types the code.
- **FR-VOTE-02** Before the start the voter sees a countdown; after the end, a closed
  message and, once published, a link to the results.
- **FR-VOTE-03** After the code is accepted the voter sees their name and group to confirm
  the slip is theirs, then the ballots they are eligible for (FR-BAL-05) one after another, then a review
  screen, then confirms. Nothing is recorded before the final confirmation.
- **FR-VOTE-04** All ballots are submitted together in one operation. Either everything
  is recorded or nothing is.
- **FR-VOTE-05** A vote is final. It cannot be changed or cast again.
- **FR-VOTE-06** A voter session lasts 15 minutes at most and ends on submission. After
  submission the screen returns to code entry so the next person can use the same device
  (computer lab, shared phone).
- **FR-VOTE-07** After voting the voter sees a confirmation with the date and time. The
  confirmation does not show the choices.
- **FR-VOTE-08** Failed code attempts are rate-limited per IP address and per election.
  Shared school networks are expected, so the per-IP limit must tolerate a whole class
  behind one address.

### 4.8 Ballot secrecy and integrity

- **FR-SEC-01** The system stores "this voter has voted" and "one vote for this candidate"
  in two places with no readable link between them: no shared identifier, no sequence
  number that aligns the two, no timestamp on the vote record. The only link is the
  encrypted audit record of FR-SEC-08.
- **FR-SEC-08** For each vote the system writes one audit record holding the vote, the
  voter and the exact time of the vote. These three values are encrypted before they are
  stored, with a key pair. The server holds only the public key
  (`VOTE_AUDIT_PUBLIC_KEY`, from the environment): it can write audit records and cannot
  read them. The private key is held by the platform operator and is never on the
  server, in the database, in the repository or in a backup. The stored row carries nothing readable that could match it to a voter: no
  voter identifier, no timestamp, no sequence number. It is written in the same
  transaction as the vote (FR-SEC-02).
- **FR-SEC-09** No screen, API endpoint, export or log gives access to the audit records.
  They are read only by the platform operator, with a command-line tool that is given
  the private key for that one use and does not store it; each use of the tool is
  written to a log that names the operator, the election and the reason. The audit
  records are never used to compute results.
- **FR-SEC-10** Audit records are kept as long as their election exists. They are not
  deleted on a schedule.
- **FR-SEC-02** Both are written in a single database transaction: it commits only if
  every write succeeded and rolls back entirely on any error, so a vote is never partly
  recorded. A voter cannot vote twice even with simultaneous requests, through three
  protections together: the transaction, a row lock on the voter taken at its start, and
  a unique constraint in the database on (election, voter) for the "has voted" record.
  A test submits the same vote many times at once and checks that exactly one is recorded.
- **FR-SEC-03** Vote counts are not available to anyone, including institution users and
  the platform admin, before the election is Closed. During the election only turnout is
  shown.
- **FR-SEC-04** The result of a general ballot is never broken down by group, on any
  screen or export, because a small group would reveal individual votes. Turnout by group
  is allowed. A group ballot's result is by nature the result of its groups (FR-BAL-06).
- **FR-SEC-05** After closing, the number of recorded votes per ballot must equal the
  number of voters eligible for that ballot who are marked as having voted. The results
  screen shows this check.
- **FR-SEC-07** Eligibility is checked on the server at submission: a submission that
  contains a ballot the voter is not eligible for, or misses one they are eligible for,
  is rejected as a whole.
- **FR-SEC-06** Votes, voters who voted and ballots of a non-Draft election cannot be
  deleted through the application.

### 4.9 Results and publication

- **FR-RES-01** During an Open election institution users see live turnout: total, by
  group, and over time.
- **FR-RES-02** After closing they see, per ballot: votes and percentage per candidate,
  blank votes, the winner(s) according to the number of seats, and the turnout.
- **FR-RES-03** A tie for the last seat is reported as a tie. The system does not break
  it. The institution can add a public note to a ballot's result.
- **FR-RES-04** Publishing creates a public results page at a stable address
  (`/results/{election-uuid}`), readable without signing in.
- **FR-RES-05** The public page has social sharing metadata and a generated preview image
  (institution, election title, winners), plus share buttons for WhatsApp, Facebook and X
  and a copy-link button.
- **FR-RES-06** Results can be downloaded as PDF (official report) and as an image
  sized for social media.
- **FR-RES-07** The institution has a public profile page listing its published elections,
  at `/institutions/{institution-uuid}`.
- **FR-RES-08** The institution chooses per election whether the public page shows full
  counts or only winners and turnout.

- **FR-RES-09** From a tied ballot, the institution can create a new draft election
  holding only that ballot and the tied candidates (a second round), with the same voters.

### 4.10 Audit log

- **FR-AUD-01** The system records institution user actions: sign-in, election status
  changes, time changes, voter import, voter edit and deletion, credential generation,
  reissue, download and sending, publication.
- **FR-AUD-02** Each entry has: who, what, when, IP address. Entries cannot be edited or
  deleted through the application.
- **FR-AUD-03** The owner can read and export the log of their institution.

### 4.11 Platform administration

- **FR-ADM-01** The platform admin lists institutions with their election counts, and can
  suspend or reactivate one.
- **FR-ADM-02** The platform admin has no screen exposing votes, credentials or voter
  contact details.

### 4.12 Dashboard and navigation

- **FR-NAV-01** After sign-in an institution user lands on a dashboard showing: the open
  election with its turnout and closing time, a to-do list (voters who have not voted,
  unfinished drafts, users without two-factor authentication), key figures, recent
  activity and the latest elections.
- **FR-NAV-02** The side menu gives direct access to every section of the selected
  election and has a "go to" search over pages, elections and voters. Any screen is
  reachable in at most three clicks from any other.
- **FR-NAV-03** The admin area is designed for a desktop screen first and remains usable
  on a phone. The voting flow and the public pages are designed for a phone first.
- **FR-NAV-04** Lists are shown as cards (list and detail) rather than tables wherever
  the content allows.

---

## 5. Non-functional requirements

### Security

- **NFR-SEC-01** OWASP ASVS level 2 is the reference for the admin area and the voting
  flow.
- **NFR-SEC-02** Passwords are hashed with Argon2id. Credentials are stored as a keyed
  hash.
- **NFR-SEC-03** Tenant isolation is enforced on the server for every request and covered
  by automated tests.
- **NFR-SEC-04** HTTPS only, HSTS, a strict Content-Security-Policy, secure and
  HTTP-only cookies, CSRF protection on every state-changing request.
- **NFR-SEC-05** Rate limiting on sign-in, password reset, code entry and file upload.
- **NFR-SEC-06** Uploaded files are validated by content, not by extension. Spreadsheets
  are parsed without evaluating formulas; exported cells are protected against formula
  injection.
- **NFR-SEC-07** Secrets come from environment variables, never from the repository.
- **NFR-SEC-08** Every identifier that appears in a URL, a link, a file name or an API
  response is a random UUID (version 4). Internal numeric identifiers never leave the
  server.
- **NFR-SEC-09** Production uses its own audit key pair, different from any development
  pair and generated on the operator's machine, not on the server. Only the public key is
  sent to the server. The operator keeps the private key and a backup of it away from
  the server and from the database backups. If it is lost, the audit records can no
  longer be read; votes and results are not affected.

### Performance and reach

- **NFR-PERF-01** The voting pages are usable on a 3G connection and a low-end Android
  phone: under 150 KB transferred for the first voting screen excluding candidate photos,
  photos lazy-loaded and served at the displayed size.
- **NFR-PERF-02** 500 voters can submit within one minute on the reference deployment
  with no error and no lost vote.
- **NFR-PERF-03** Imports, PDF generation and email sending run in background jobs.

### Usability and language

- **NFR-UX-01** Interface in French (default) and English. The
  institution picks the language of each election; the voter can switch.
- **NFR-UX-02** Mobile-first. The voting flow works from 320 px width.
- **NFR-UX-03** WCAG 2.1 AA for the voting flow and the public results page.

### Operations

- **NFR-OPS-01** The whole system starts with one `docker compose up` in development.
- **NFR-OPS-02** Daily database backup with a documented restore procedure.
- **NFR-OPS-03** Opening and closing elections on time does not depend on a user being
  connected; a late scheduler does not let a vote in after the end time (see FR-ELEC-05).
- **NFR-OPS-04** Structured application logs that never contain codes, passwords or vote
  content.

---

## 6. Main data entities

Conceptual only; the schema is defined in the design phase.

```
Institution 1─* InstitutionUser
Institution 1─* Election
Election    1─* Ballot      1─* Candidate *─0..1 Party
Election    1─* Party
Election    1─* Group       1─* Voter
Ballot      *─* Group           (empty = general ballot)
Election    1─* Voter       1─0..1 Credential
Election    1─* Participation   (voter has voted, when)
Ballot      1─* Vote            (candidate or blank; no readable link to Voter)
Election    1─* VoteAudit       (encrypted: vote, voter, time)
Institution 1─* AuditEntry
```

---

## 7. Technology

| Layer | Choice |
|---|---|
| API | Laravel (current stable), PHP 8.4, REST + JSON |
| Web | Next.js (App Router), TypeScript |
| Database | MariaDB (current LTS) |
| Cache, queue, sessions | Redis |
| Styling | Tailwind CSS |
| Runtime | Docker Compose: reverse proxy, API, queue worker, scheduler, web, MariaDB, Redis, Mailpit (dev) |

**Why Tailwind rather than Bootstrap or plain CSS**

- It ships only the classes actually used, typically 10–20 KB of CSS, and no JavaScript.
  Bootstrap adds its whole stylesheet plus a JS bundle. This matters directly for
  NFR-PERF-01.
- It is the default styling path in Next.js, so there is no integration work.
- Colours, spacing and type are tokens in one config, which makes per-institution accents
  (logo colour on the voting page) a small change rather than an override battle.
- Styles live next to the markup, which makes generated code easier to review: a reviewer
  sees the whole component in one file.
- Plain CSS would give the same weight but would have us reinvent spacing scales,
  responsive rules and focus states.

Accessible interactive pieces (dialogs, menus, tabs) come from an unstyled component
library styled with Tailwind; the exact one is chosen in the design phase.

---

## 8. Constraints

- Hosting budget is small: the system must run on one modest VPS.
- Email is treated as a paid, optional channel. Paper is the first-class channel.
- Many voters are minors: collect the minimum (FR-VOT-01).

---

## 9. Acceptance scenario for v1

1. A school registers, verifies its email and completes its profile.
2. It creates an election "Student council 2026" with general ballots President and
   Secretary and one group ballot "Class delegate" for each of two classes, two parties,
   and three candidates per ballot, one without a photo.
3. It imports 300 students from an Excel file containing 4 invalid rows, fixes them,
   and ends with 300 voters in 8 groups.
4. It generates printed slips for 280 voters and sends emails to 20.
5. The election opens automatically. Students vote from phones and from one shared
   computer. A student of a class with a delegate ballot sees three ballots; the others
   see two. One student loses their slip and gets a reissued code. A student who already
   voted tries again and is refused.
6. During the election the school sees turnout by group and no vote count.
7. The election closes automatically. The school sees the results; the integrity check
   passes.
8. The school publishes. The public page opens without signing in and shows a correct
   preview when shared on WhatsApp.
9. A second school, signed in, cannot reach any of the first school's data.

---

## 10. Delivery

The system is delivered in vertical slices: one small feature at a time, built end to
end (database, API, screen, tests), each as one pull request. Acceptance tests are
written from this specification before the code of a slice, and every test names the
requirement ID it checks. Each slice is reviewed for correctness, security and use of
current, supported versions before it is merged.

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| A printed slip is given to or taken by the wrong student | The voting screen shows the voter's name before the ballots; slips are sorted by class for the teacher; reissue invalidates a lost code. |
| A whole class behind one IP address triggers rate limits | Limits combine IP and election and are sized for shared networks (FR-VOTE-08). |
| Connection drops during submission | Submission is atomic and safe to retry; the voter is told clearly whether the vote was recorded. |
| The institution distrusts the result | Integrity check (FR-SEC-05), audit log, turnout by group, official PDF report. |
| Emails land in spam | Paper is the default channel; the self-distribution file is a fallback. |
| Someone with access to the server reads how students voted | The server holds only the public key and cannot decrypt the audit records (FR-SEC-08). |
| The private audit key leaks or is lost | It is kept by the operator alone, off the server, with a separate backup; the read tool logs every use (FR-SEC-09); production has its own pair (NFR-SEC-09). |

---

## 12. Decisions and open questions

### Decided on 2026-10-05

| Subject | Decision |
|---|---|
| Voters | Per election; loading them from a previous election is required (FR-VOT-05). |
| Ballot scope | General ballots for the whole school and group ballots for chosen groups, in the same election (FR-BAL-03). |
| Seats | One by default; more if the institution chooses (FR-BAL-02). |
| Social media | Public results page with share buttons and preview image; no automatic posting in v1. |
| Registration | Open self-registration with email verification; the platform admin can suspend. |
| Languages | French and English. |
| Ties | Reported as a tie with no winner; the school decides outside the system and can add a public note (FR-RES-03). |
| Redis | Added to the stack for queues, cache and rate limiting. |
| Paper | Printed slips use US Letter, not A4. |
| Screens | The mockups in `docs/design/mockups/` are the approved design (voter flow, admin, public results). |

### Decided on 2026-10-06

| Subject | Decision |
|---|---|
| Vote audit | Each vote is linked to its voter and time in an encrypted record, for audit only (FR-SEC-08, 09). Never shown in the admin interface. |
| Audit key | A key pair: the public key on the server, the private key with the platform operator only (FR-SEC-08, NFR-SEC-09). |
| Audit retention | Kept as long as the election exists (FR-SEC-10). |
| Public addresses | Every public link and every identifier leaving the server is a random UUID; slugs are dropped (NFR-SEC-08, FR-RES-04, FR-RES-07). |

### Open

None.
