# Slice 20: load, backup, production (what was done, what is left)

Covers NFR-PERF-02, NFR-OPS-02, NFR-SEC-09 and SPEC section 8. Done on this machine with the production images; the VPS is
the one part that needs Jun and his approval to connect.

| Item | State | Where |
|---|---|---|
| 500 voters vote within a minute, no lost vote | **Done**: 500/500 in 60 s, 500/500 in a 3 s burst, 1 000/1 000 in 60 s, 0 errors, tallies exact, bulletin check ok. A finding: the per-address limit (600 a minute) refused voters from the 301st when one address carries a school; raised to 3 000 | `docs/ops/load-test.md`, `tools/loadtest/vote_load.py` |
| A backup is restored following the written procedure | **Done**: counts identical row for row after a dropped database; two faults of the restore found and fixed (pictures on the Docker driver, migrations through the migrator account) | `docs/ops/backup-restore.md` |
| The production compose file runs, with its own audit key | **Done locally** (`install-online.sh --behind-proxy`, own key pair per installation, private half in a take-away file); **not on a VPS** | `docs/ops/production-vps.md` |
| CSP of the production build, every kind of page | **Done**: 0 violations, 0 script errors on 31 page kinds (public, sign-in, organiser, voter, results, guide) | `web/e2e/slice20/csp-production.spec.ts` |
| `SITE_URL` in production | **Done**: set by the installer, passed to the web container, used by the sharing metadata | `compose.prod.yaml`, `installers/online/lib/online.sh` |
| Migrate and `db:grant-app` on deploy | **Done**: installer, `votectl update` and `votectl restore` all go through the one-off `migrate` service (Docker) or the installer (native) | `installers/` |
| PDF Unicode font | **Decision, no code**: Latin letters beyond Windows-1252 (Latin Extended-A) are drawn as their plain letters and the curly quotes as straight ones (`ReportDocument::LATIN`); a script outside Latin (Arabic, Cyrillic, Greek, CJK) still prints `?`. Embedding a Unicode font was judged out of scope for French, English and Haitian Creole institutions; reopen if an institution needs another script | `api/app/Support/Reports/ReportDocument.php` |
| The VPS rollout | **Waiting for Jun**: the VPS, the domain, the SMTP account, and approval for each SSH connection | `docs/ops/production-vps.md` |

## Changed in code

- `api/config/votes.php`: `public_per_minute` 600 to 3 000.
- `installers/common/votectl`: restore of the pictures and migrations on the Docker driver.
- `installers/linux/lib/setup.sh`: the health wait needs a 2xx, not any answer (a proxy answers 502 while the API starts).
- `installers/linux/tests/vm-*`: an unattended test of the Linux installer in a fresh cloud-image VM (cloud-init, serial
  console), run on Debian 12, Ubuntu 24.04 and Ubuntu 26.04.

## Test results of the night run (2026-10-13)

| Suite | Result |
|---|---|
| API, folder by folder (one process of everything hits PHP's 256 MB limit) | **2 531 passed**: Unit 130, Feature 200, Architecture 2, Slices 01 to 19c 2 199 |
| Web unit (vitest) | passed: 87 files, 684 tests |
| `make lint` (Pint, PHPStan, ESLint, Prettier, tsc) | green after fixing the `mail:test` types and the formatting of `motion.css` |
| `make generate-check` | green: `openapi.json` and `schema.d.ts` unchanged |
| Browser, both projects (`make e2e`), the machine also running the API tests, a VM and a stack | 915 passed, 32 skipped, 7 failed; 6 passed again alone on a quiet machine (load: ballots delete, candidates drag, users re-invite, axe checks on phone); the 7th, `slice03/shell.spec.ts:115`, is the known one: the development error overlay puts a `data-nextjs-dialog-error-index="0"` on the page (it is a development-server artefact; the production build has none, see the CSP check) |
| On demand (not in `make e2e`): guide pictures, production CSP, school edition | capture 47 pictures; CSP 0 violations; school edition 4 of 4 |
| Linux installer in fresh VMs | Debian 12, Ubuntu 24.04.5, Ubuntu 26.04.1: PASS |
| Windows installer in a VM | install, flow, update, rollback, backup, restore, uninstall, reboot: PASS |
