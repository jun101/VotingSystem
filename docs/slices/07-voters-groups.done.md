# Slice 07 done (2026-10-10)

Voters and groups of an election, managed in modals on one page (screen A08). FR-VOT-01, 02, 06, 08, FR-SEC-06.

- API: nine endpoints (list, add, edit, delete voters; list, add, rename, delete, merge groups), tables `voter_groups` and `voters`, group found or created by name, limits 10 000 voters and 100 groups, status rules (`election_voters_locked`), named rate limiters.
- Open election: voters may be added and edited, but never deleted, and groups are not created or changed (a voter keeps their group).
- Page: `/admin/elections/{election}/voters`, voter cards two per row, search and group chips, pager kept in the address, groups card in the rail, voter and group modals, delete and merge dialogs. The election step "Électeurs" links to it.
- Checks at the end: Slice07 API folder 157 passed (whole suite 1464 passed before the last fixes), web unit 390, browser slice07 36 passed, slice06 110 and theme 94 passed, lint clean.
- Review: `07-voters-groups.review.md`, one round (correctness, security, id-leak), all findings closed.
- Two acceptance tests (429 on delete) were corrected: a 404 is not counted by the limiter, so they delete real records, as in slice 06.
- Not in this slice: import (slice 08), group scope on ballots and loading voters from a past election (09), codes (10), "has voted" and deleting in an open election (12).
