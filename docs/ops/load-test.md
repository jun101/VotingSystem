# Load test: 500 voters in a minute (NFR-PERF-02)

Tool: `tools/loadtest/vote_load.py` (Python standard library, and `pdftotext` for the codes). It does what an
organiser and voters do: registers a throw-away institution, uploads a CSV of N voters, runs the codes, reads the codes
back from the slips, schedules the election, waits for the scheduler to open it, then makes every voter go through the real
flow (session, ballots, submit) from one address, their arrival spread over a time or all at once. It then closes the election
and checks the results against what it sent: every vote counted, every tally exact, the bulletin check ok.

```bash
tools/loadtest/vote_load.py --base http://127.0.0.1:8081 --voters 500 --seconds 60 --threads 150
tools/loadtest/vote_load.py --base http://127.0.0.1:8081 --voters 500 --burst --threads 200
```

## Result (2026-10-13)

Stack: the production images (`docker build --target prod`) from `compose.prod.yaml`, Octane on FrankenPHP, MariaDB 12.3,
Redis 8, Caddy, on a laptop (8 cores, 15 GB, also running other things), every request from one address.

| Run | Votes accepted | Errors | Wall time | p95 session / ballots / submit | Results |
|---|---|---|---|---|---|
| 500 voters, spread over 60 s | 500 of 500 | 0 | 59.8 s | 6 / 6 / 18 ms | cast 500, check ok, tallies exact |
| 500 voters, all at once | 500 of 500 | 0 | 3.1 s | 397 / 319 / 443 ms | cast 500, check ok |
| 1 000 voters, spread over 60 s | 1 000 of 1 000 | 0 | 60.0 s | 5 / 6 / 16 ms | cast 1 000, check ok |

Reports: `docs/ops/reports/`. The target (500 voters in a minute, no vote lost) is met with a wide margin: the server
did the whole minute's work in a few milliseconds per request.

## What the test found, and the change

From a single address the first run refused voters from the 301st: HTTP 429 `too_many_attempts` on the session route.
The cause was the limit on the voters' routes, 600 requests a minute per address and election, and a voter's visit is
about 4 of them (information, session, ballots, submit). That is some 150 voters a minute from one address, below the
target when a whole school sits behind one address (a NAT in a computer lab).

`votes.public_per_minute` (`api/config/votes.php`) is now **3 000**: some 700 voters a minute from one address. This limit
protects the servers; it is not the guard of the codes (wrong codes are counted apart: 30 per 10 minutes per address and
election, 300 per hour per election, and a valid code is never refused by that counter). The unit test of the limiter sets
its own value and is unchanged.

## Not covered

- A second run on the VPS itself (different hardware, real network): do it once after the rollout, with the same command
  from another machine, and `--voters 500 --seconds 60`.
- Several elections voting at the same time in one institution or on one server: each has its own limits; the database
  work per vote is the same, so the figures above scale with the number of votes a minute, not the number of elections.
