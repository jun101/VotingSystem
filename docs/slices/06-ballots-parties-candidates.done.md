# Slice 06 done (2026-10-10)

Ballots, parties (with logo), candidates (with photo), all managed in modals on the ballots page. FR-BAL-01, 02, 04 and FR-CAND-01 to 04.

- API: ballots, parties, party logo, candidates, candidate photo, reorder of ballots and candidates, duplicate copies ballots, parties and (option) candidates.
- Page: `/admin/elections/{election}/ballots`, adaptive layout (1280 to 2560, phone 360), drag and arrows, checks card, modals.
- Checks at the end: API 1318 passed, web unit 343, browser slice06 110 passed (2 self-skipped), lint clean.
- Review: `06-ballots-parties-candidates.review.md`, one round, all should-fix done.
- Not in this slice: group scope (slice 09), voters (07), import (08, mockup drawn).
