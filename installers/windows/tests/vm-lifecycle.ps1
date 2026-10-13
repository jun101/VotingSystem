# Run in the Windows test VM after install.ps1 and an extracted bundle in C:\vsbundle: dry run, update, refusal of the same
# version, a failed update that must roll back, backup and restore, uninstall keeping the data. See README.md.
$ErrorActionPreference = 'Continue'
$src = (Get-ChildItem C:\vsbundle -Directory | Select-Object -First 1).FullName
function Health { try { (Invoke-WebRequest -Uri 'http://127.0.0.1/api/v1/health' -UseBasicParsing -TimeoutSec 10).StatusCode } catch { 'no answer' } }
function Inst($dir, [string[]]$more) { & powershell -NoProfile -ExecutionPolicy Bypass -File "$dir\installers\install.ps1" @more -Lang en 2>&1 | Out-String }
function Votectl($more) { & powershell -NoProfile -ExecutionPolicy Bypass -File C:\VoteSystem\installer\votectl.ps1 @more 2>&1 | Out-String }

"=== votectl user list"; Votectl @('user', 'list')
"=== update, dry run"
Remove-Item C:\vsb2 -Recurse -Force -ErrorAction SilentlyContinue
& robocopy $src C:\vsb2 /E /NFL /NDL /NJH /NJS /NP | Out-Null
Set-Content C:\vsb2\VERSION '0.1.1-test' -Encoding ASCII
Inst 'C:\vsb2' @('update', '-DryRun')
"version still: $(Votectl @('version'))"
"=== update to 0.1.1-test"
$sw = [Diagnostics.Stopwatch]::StartNew()
Inst 'C:\vsb2' @('update')
"after: version $((Votectl @('version')).Trim()), health $(Health), $([int]$sw.Elapsed.TotalSeconds) s"
"releases: $((Get-ChildItem C:\VoteSystem\releases -Directory).Name -join ', ')"
"=== the same version again is refused"
Inst 'C:\vsb2' @('update')
"=== failed update (a broken migration) must roll back"
Remove-Item C:\vsb3 -Recurse -Force -ErrorAction SilentlyContinue
& robocopy $src C:\vsb3 /E /NFL /NDL /NJH /NJS /NP | Out-Null
Set-Content C:\vsb3\VERSION '0.1.2-test' -Encoding ASCII
Set-Content C:\vsb3\api\database\migrations\2099_01_01_000000_boom.php '<?php throw new Exception("boom");' -Encoding ASCII
Inst 'C:\vsb3' @('update')
"after failure: version $((Votectl @('version')).Trim()), health $(Health)"
"=== backup and restore"
$b = ((Votectl @('backup')) -split "`n" | Where-Object { $_ -match '\.zip' } | Select-Object -Last 1).Trim()
"backup: $b ($([math]::Round((Get-Item $b).Length / 1KB)) KB)"
Votectl @('restore', $b)
"after restore: health $(Health)"
"=== uninstall keeping the data"
Inst 'C:\vsb2' @('uninstall')
"prefix gone: $(-not (Test-Path C:\VoteSystem)); data kept: $(Test-Path C:\VoteSystemData\config\env); tasks left: $((Get-ScheduledTask -TaskName 'VoteSystem-*' -ErrorAction SilentlyContinue | Measure-Object).Count); firewall rule left: $((Get-NetFirewallRule -DisplayName 'VoteSystem web' -ErrorAction SilentlyContinue | Measure-Object).Count)"
"processes left: $((Get-Process -Name caddy, node, php-cgi, mariadbd, redis-server -ErrorAction SilentlyContinue | Measure-Object).Count)"
