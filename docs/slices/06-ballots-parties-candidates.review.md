# Slice 06 review (2026-10-10)

Four reviewers read `git diff a2f953d..37d47e7`: correctness, security, id leak, modernity.

## Fixed (branch slice/06e-review-fixes)

| Id | Finding | Fix |
|---|---|---|
| S1 | Reorder lists had no maximum; no request body limit | `max:50` on both lists; `request_body max_size 8MB` on `/api/*` in the Caddyfile |
| C1 | 409 in the candidate modal gave no page notice or reload | `onLocked` calls `locked()` |
| C2 | 422 `set_mismatch` on reorder never reloaded the list | the list is read again and a notice says it changed |
| C3 | A reorder answer wiped candidates or ballots added meanwhile | only order and position are merged |
| C4 | Party and target ballot resolved before the election lock (500 or wrong 404) | looked up again under the lock, 422 `invalid` |
| C5 | Party modal and delete dialogs did not reload on 409 | `onLocked` added |
| C6 | Reorder judged the state before the set | order is record, body, state; tests for scenario 5b |
| C7 | Party names ignored accents | `name_key` is `utf8mb4_bin`; endpoint file says accents count |

## Left for later

- Native `<dialog>` focus trap and CSS scroll lock, ref map for focus, bulk inserts in duplicate, `$hidden` on foreign key columns, package bumps (Next 16.4, patch releases; ESLint 10 and TypeScript 7 wait).

## Clean

Id leak (no finding), tenant isolation, authorization, uploads, logs, draft-only rule, positions, cascades, orphaned files.

Also fixed this session (not from the review): the API test container shared the dev media volume and emptied it.
