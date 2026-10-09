# Slice 05 — Elections: what was done

2026-10-08 · branch `slice/05-elections`

## What you can do now

1. Sign in as an owner or manager, open **Élections**: status tiles, year chips and **Nouvelle élection** on one row,
   then a three-column grid of cards (one column on a phone).
2. Create a draft (title, description, start and end in the election's time zone, language, candidate order, results
   display, cover). The side panel shows the schedule in words and the duration.
3. Click a card for the summary page; edit or delete a draft; duplicate any election (settings only, "Copie de ...").
4. Elections that are not drafts cannot be edited or deleted (409 `election_not_editable`).

## What was built

| Part | Content |
|---|---|
| API | Eight endpoints: list (status, year, pages, counts, years), create, show, edit, delete, duplicate, cover put/delete; limits 60/h, 120/h, 20/h |
| Data | `elections` table with every column of database.md 2.2, `ends_at > starts_at` check |
| Dates | UTC storage, ISO 8601 in and out, whole seconds, years 1000-9999; the year filter follows each election's own zone |
| Cover | Re-encoded to WebP 480 and 960 px wide, never enlarged, replaced under a row lock |
| Web | List, new, summary, edit pages; time-zone helpers (`Intl` only, skipped hour moves forward, repeated hour takes the first); Show more past 100 |
| Tests | 181 acceptance tests, 16 race tests, 68 browser tests |

## Decisions (Jun)

Cover reuses the image re-encoder; duplicate copies settings only; a card opens a short summary; count tiles above a
3-column grid; one fix job after review; Show more button; hide zones the browser does not know.
Proposed and not vetoed: owners and managers both manage elections; "Toutes" does not count archived; a draft start may
be in the past.

## Changed along the way

Two slice 03 tests adjusted (approved): the coverage "unlisted route" test uses `/api/v1/ballots`, and the
"no number in a data value" check allows `data-count`.
`ImageReEncoder::store()` frees its memory caches before restoring the limit (the memory test failed once the suite
grew).

## Known and deferred

Ballot and voter counts are 0 until slice 06 and later; a vote in progress is not yet checked by the draft rule.
