# Windows spike (before the Windows installer)

Goal: prove that the application can run **natively on Windows** (no Docker, no WSL), or learn early that it cannot.
Result decides step F of slice 19c: native `install.ps1`, or WSL2 plus the Linux installer inside it.

Time: about 1 hour on a Windows 10/11 or Windows Server 2022 PC with 8 GB RAM and 10 GB free. Use a PC that is not
important; nothing here needs the internet except the downloads in part 2.

## Part 1: look only (2 minutes, changes nothing)

1. Copy `spike.ps1` to the PC.
2. Run: `powershell -ExecutionPolicy Bypass -File .\spike.ps1`
3. Send me `spike-report.txt`.

## Part 2: does it run (I will prepare the exact download list after part 1)

Each item is a yes or no with the error message if no.

| # | Check | Pass when |
|---|---|---|
| 1 | PHP 8.4 NTS (x64) from windows.php.net with `pdo_mysql bcmath opcache gd exif zip sodium mbstring openssl intl` | `php -m` lists them |
| 2 | MariaDB (zip, no installer) starts as a service on a free port | `SELECT VERSION()` answers |
| 3 | Our migrations run on it | `php artisan migrate` ends with no error |
| 4 | A Redis-compatible store (candidate: Garnet) | `php artisan tinker` can `Cache::put` and `Redis::incr` |
| 5 | Laravel Octane with RoadRunner serves the API | `GET /api/v1/health` answers 200 |
| 6 | Queue worker runs without `pcntl` | a queued job (PDF of a slip) finishes |
| 7 | Next.js standalone build runs with Node 24 | the home page answers 200 |
| 8 | Caddy (Windows build) routes `/api`, `/media`, and the web app on one port | the browser flow works on one address |
| 9 | The vote flow end to end | an election with 1 voter and 1 ballot: code, vote, result |
| 10 | Windows Firewall rule can be added and removed from the script | `New-NetFirewallRule` then `Remove-NetFirewallRule` |

Anything that fails is written in the result with the exact message. Two failures in items 4 to 6 mean the fallback.
