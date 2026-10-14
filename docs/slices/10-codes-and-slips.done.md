# Slice 10 done (2026-10-11)

Access codes and printed slips: generate for all, a group or the voters with no group; reissue one voter; a US Letter PDF per group, 10 slips a page with cut lines and a QR code; download until the election closes. Screen A11. FR-CRED-01, 02, 03, 04 (printed slips), 05, 06; FR-CRED-08 as log lines only (entries in slice 17).

- API: six endpoints in `docs/api/credentials/` (state, runs, files list, file status, download, reissue); tables `credentials` and `generated_files`; one queued job per file; private disk `credentials` with `Crypt`-encrypted PDFs under random names; daily `files:purge`.
- Codes: 10 characters of an alphabet without look-alikes, shown `XXXXX-XXXXX`, CSPRNG; only the HMAC-SHA-256 is stored; a code appears in the slip file and in one reissue answer only.
- Slips: TCPDF (`tecnickcom/tcpdf` 7, bundled Helvetica definitions in `api/resources/pdf-fonts/core/`), 2 columns by 5 rows, dashed cut lines, QR payload `{APP_URL}/vote/{election}#{CODE}`; 1 000 slips take 11 to 12 s and 105 MB.
- Rules: draft, scheduled or open elections only; owner and manager; limits 20 runs, 60 reissues, 120 downloads an hour; a run serves only voters with no code; a failed file leaves no code behind.
- Page: `/admin/elections/{election}/codes` (totals, one card per group, generate, download every file, lost-slip dialog with the code shown once); step 4 of the election page and the codes card of the voters page link to it.
- Checks at the end: whole API suite 1869 passed (with the one test corrected by the test author), web unit 514, browser slice10 16, slice07 36, theme 94, slice05 66, lint clean.
- Review: `10-codes-and-slips.review.md` (correctness and security, one round).
- Test changes after the test commit, made by the test author: the state line keeps its group as an object `{group: {id, name}|null}` (the leak scanner refuses a null `id`), three test-call slips, a suspended-user test split in two, the stale-file test of the purge, and the theme test for step 4.
- Not in this slice: email and the self-distribution file (11), the voting page (12), "has voted" refusal for a reissue (12), audit entries (17).
