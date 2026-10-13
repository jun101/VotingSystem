<#
votectl: the operator's command for a VoteSystem installation (school edition, Windows).

  votectl status | version
  votectl logs [SERVICE]
  votectl admin create EMAIL
  votectl owner create EMAIL --institution NAME [--name N] [--language fr|en]
  votectl user reset-password EMAIL [--credentials-file FILE]
  votectl user list
  votectl 2fa reset EMAIL
  votectl backup [--out DIR]
  votectl restore FILE
  votectl start | stop | restart

The accounts, the audit and the elections are never read by this script: it only calls the application's own commands.
#>
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $Here 'lib\common.ps1')

function Die([string]$m) { Write-Host "votectl: $m"; exit 1 }
function Need-Admin {
  if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Die 'run it in a PowerShell opened as administrator'
  }
}
function Option-Value([string[]]$Words, [string]$Name) {
  for ($i = 0; $i -lt $Words.Count - 1; $i++) { if ($Words[$i] -eq $Name) { return $Words[$i + 1] } }
  $null
}

$all = @($args)
if ($all.Count -eq 0) { $all = @('help') }
$cmd = $all[0]
$rest = @()
if ($all.Count -gt 1) { $rest = @($all[1..($all.Count - 1)]) }

switch ($cmd) {
  'help' { Get-Content $MyInvocation.MyCommand.Path | Select-Object -Skip 1 -First 17 }
  'version' { Write-Host (Get-Release) }
  'status' {
    Write-Host "Version : $(Get-Release)"
    Get-VsTasks | Sort-Object TaskName | ForEach-Object { '{0,-26} {1}' -f ($_.TaskName -replace "^$($script:TaskPrefix)", ''), $_.State }
    $map = Read-EnvFile
    if ($map.WEB_PORT) {
      $health = try { (Invoke-WebRequest -Uri "http://127.0.0.1:$($map.WEB_PORT)/api/v1/health" -UseBasicParsing -TimeoutSec 8).Content } catch { "no answer ($($_.Exception.Message))" }
      Write-Host "Health  : $health"
    }
    $drive = (Split-Path -Qualifier $script:DataDir).TrimEnd(':')
    Write-Host ('Disk    : {0:N1} GB free on {1}:' -f ((Get-PSDrive $drive).Free / 1GB), $drive)
  }
  'logs' {
    Write-Host 'The services write to the Windows Task Scheduler history and to the application log:'
    $log = Join-Path $script:DataDir 'storage\logs'
    if (Test-Path $log) { Get-ChildItem $log -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1 | ForEach-Object { Get-Content $_.FullName -Tail 60 } }
  }
  'start' { Need-Admin; Start-AllVs }
  'stop' { Need-Admin; Stop-AllVs }
  'restart' { Need-Admin; Stop-AllVs; Start-Sleep 3; Start-AllVs }
  'admin' {
    Need-Admin
    if ($rest.Count -lt 2 -or $rest[0] -ne 'create') { Die 'usage: votectl admin create EMAIL' }
    Invoke-Artisan platform:create-admin $rest[1]
  }
  'owner' {
    Need-Admin
    if ($rest.Count -lt 2 -or $rest[0] -ne 'create') { Die 'usage: votectl owner create EMAIL --institution NAME' }
    $a = @($rest[1])
    foreach ($o in '--institution', '--institution-uuid', '--name', '--language', '--credentials-file') {
      $v = Option-Value $rest $o
      if ($v) { $a += "$o=$v" }
    }
    Invoke-Artisan owner:create @a
  }
  'user' {
    Need-Admin
    switch ($rest[0]) {
      'list' { Invoke-Artisan user:list }
      'reset-password' {
        if ($rest.Count -lt 2) { Die 'usage: votectl user reset-password EMAIL [--credentials-file FILE]' }
        $a = @($rest[1])
        $f = Option-Value $rest '--credentials-file'
        if ($f) { $a += "--credentials-file=$f" }
        Invoke-Artisan user:reset-password @a
      }
      default { Die 'usage: votectl user list | reset-password EMAIL' }
    }
  }
  '2fa' {
    Need-Admin
    if ($rest.Count -ne 2 -or $rest[0] -ne 'reset') { Die 'usage: votectl 2fa reset EMAIL' }
    Invoke-Artisan auth:reset-two-factor $rest[1]
  }
  'backup' {
    Need-Admin
    $out = Option-Value $rest '--out'
    $file = if ($out) { New-VsBackup -OutDir $out } else { New-VsBackup }
    Write-Host $file
    # Keep the last 14 nightly backups.
    Get-ChildItem $script:Backups -Filter 'votesystem-*.zip' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -Skip 14 | Remove-Item -Force
  }
  'restore' {
    Need-Admin
    if ($rest.Count -lt 1) { Die 'usage: votectl restore FILE' }
    Stop-VsService 'proxy'; Stop-VsService 'web'; Stop-VsService 'queue'; Stop-VsService 'scheduler'; Stop-VsService 'php-*'
    Invoke-VsRestore $rest[0]
    Start-AllVs
    Write-Host 'Restored.'
  }
  default { Die "unknown command '$cmd' (try: votectl help)" }
}
