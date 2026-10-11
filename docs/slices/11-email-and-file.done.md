# Slice 11 done (2026-10-11)

Sending the codes by email after a confirmed count, the self-distribution file (Excel or CSV), the status of each message, and the credential and email filters of the voters list. Screen A11 (send dialog, status, files). FR-CRED-04 (email and file), FR-CRED-05, FR-CRED-06, FR-CRED-07, FR-VOT-06 (credential status); FR-CRED-08 as log lines only (audit entries in slice 17).

- API: `POST /elections/{election}/credential-previews`; credential runs take `email`, `confirm_emails`, `file` and scope `failed_email`; reissue takes `email`; state gets email counts; voters get `has_code`, `email_status` and the filters `credential` and `email`; code files `codes_xlsx` and `codes_csv` are listed and downloaded like slips.
- Email: one encrypted queued job per message (voter UUID, election UUID, code), rate limited per institution inside a platform cap, retried by real send attempts, status `queued`, `sent` or `failed` with a fixed reason; the mail follows `docs/design/email.md`, in the election's language, with the personal link `{APP_URL}/vote/{election}#{CODE}`.
- File: assembled across the run's per-group jobs from an encrypted partial, sorted by group then name, header per language, every text cell through `FormulaGuard`, named `codes-{slug}`, `codes-tous` or `codes-sans-groupe`.
- Rules: the user's email must be verified to send emails (403 `email_not_verified`); at most 5 000 emails per institution per 24 hours (409 `email_quota_exceeded`); the count the person confirmed must still be right (409 `email_count_changed`); a voter who already has a code is not emailed by a run (reissue emails one by one).
- Page: "Générer et envoyer" dialog with the numbers and the voters left for print, email status per group and in total, resend of failed messages, code files to download; the lost-slip dialog can also email; the voters page filters and chips.
- Checks at the end: whole API suite 1984 passed, web unit 557+, browser slice11 14 (desktop and phone), slice10 18, slice07 36, theme 94, lint clean.
- Review: `11-email-and-file.review.md` (correctness and security, one round).
- Test changes after the test commit, by the test author: key lists of three older tests for the new fields, the recipient-only assertion, the colour regex that must ignore the link's `#CODE`, and the browser tests verify the address first.
- Not in this slice: the voting page (12), the "has voted" refusal for a reissue (12), SMS or WhatsApp, delivery reports from the mail provider, audit entries (17).
