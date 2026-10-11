# Slice 09 done (2026-10-10)

Group scope on ballots, loading voters from another election, the readiness checklist and scheduling. FR-BAL-03, FR-BAL-05, FR-BAL-06, FR-VOT-05, FR-ELEC-04 (schedule), FR-SEC-06. Screens A05 (checklist card, schedule and unschedule dialogs), A06 (scope chips), A07 (copy dialog).

- Run 09a, group scope: pivot `ballot_voter_group` (ballot cascade, group restrict); ballot fields `scope`, `groups`, `eligible_voters`, `warnings` (`no_voters`, `few_voters` under 5); groups carry `ballots_count`; a group a ballot covers cannot be deleted; a merge moves the ballots without duplicates, also while scheduled.
- Run 09b, copy: `POST /elections/{election}/voter-copies` (source any other election of the institution, any status; some groups, with or without the ungrouped, renaming; one transaction, limits checked first, 20 an hour) and the dialog on the voters page.
- Run 09c, checklist and scheduling: `GET /elections/{election}/checklist` (blockers `no_ballots`, `ballot_without_candidates`, `no_voters`; warnings `ballot_single_candidate`, `group_ballot_no_voters`, `group_ballot_few_voters`, `voters_without_ballot`), `POST /schedule` (409 `checklist_blocked`, `checklist_warnings` unless `accept_warnings`, `election_period_over`, `election_not_editable`), `POST /unschedule`; `error.items` carries the faulty items; limiter 120 an hour.
- Rules kept: ballots, candidates and parties are locked while scheduled; voters and groups stay editable.
- Duplicating an election copies ballots as general (the copy has no voters or groups): written in the duplicate endpoint file.
- Checks at the end: whole API suite 1726 passed, web unit 475, browser slice09 and slice07 60 passed (slice05, 06, 08 and theme green after 09c), lint clean.
- Review: `09-scope-copy-schedule.review.md`, one round (correctness, security, id-leak); fixes in a second coding run.
- Test changes by the test author after the coding step: the `range(1, 0)` helper in `ChecklistTest`; four older tests adjusted to the contract (scope no longer an ignored field).
- Not in this slice: codes and slips (10), emails (11), "has voted" (12), opening at the start time (13).
