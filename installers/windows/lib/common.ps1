# Shared by install.ps1 and votectl.ps1 (school edition, Windows). Windows PowerShell 5.1 or later, as administrator.
# Paths come from the variables below so a test can point them at a folder of its own.

$script:Prefix  = if ($env:VOTE_PREFIX) { $env:VOTE_PREFIX } else { 'C:\VoteSystem' }
$script:DataDir = if ($env:VOTE_DATA)   { $env:VOTE_DATA }   else { 'C:\VoteSystemData' }
$script:Current = Join-Path $script:Prefix 'current'
$script:ConfigDir = Join-Path $script:DataDir 'config'
$script:EnvFile = Join-Path $script:ConfigDir 'env'
$script:Backups = Join-Path $script:DataDir 'backups'
$script:TaskPrefix = 'VoteSystem-'
$script:FirewallName = 'VoteSystem web'

# Services in start order (the php-cgi workers are VoteSystem-php-<port>).
$script:Services = @('mariadb', 'redis', 'php', 'queue', 'scheduler', 'web', 'proxy')

# Runs a native program the way a script wants: its error output is text, not a PowerShell error. $LASTEXITCODE stays set.
function Invoke-Native([scriptblock]$Block) {
  $old = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  try { & $Block 2>&1 | ForEach-Object { "$_" } } finally { $ErrorActionPreference = $old }
}

function New-Hex([int]$Bytes) {
  $buffer = New-Object byte[] $Bytes
  $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
  $rng.GetBytes($buffer)
  $rng.Dispose()
  -join ($buffer | ForEach-Object { $_.ToString('x2') })
}

function Test-PortFree([int]$Port) {
  -not (Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

# The first free port from $Start (the preferred one is taken when it is free).
function Find-FreePort([int]$Start, [int[]]$Avoid = @()) {
  for ($p = $Start; $p -lt $Start + 200; $p++) {
    if ($Avoid -contains $p) { continue }
    if (Test-PortFree $p) { return $p }
  }
  throw "no free port from $Start"
}

function Read-EnvFile([string]$Path = $script:EnvFile) {
  $map = @{}
  if (Test-Path $Path) {
    foreach ($line in Get-Content $Path -Encoding UTF8) {
      if ($line -match '^\s*#' -or $line -notmatch '=') { continue }
      $k, $v = $line -split '=', 2
      $map[$k.Trim()] = $v.Trim().Trim('"')
    }
  }
  $map
}

function Get-Release { if (Test-Path $script:Current) { (Get-Item $script:Current).Target | Split-Path -Leaf } else { $null } }

# artisan ARGS...: the application's own commands, with the installation's environment (the .env of the current release).
function Invoke-Artisan {
  $php = Join-Path $script:Current 'php\php.exe'
  $ini = Join-Path $script:ConfigDir 'php.ini'
  $arguments = @($args)
  Push-Location (Join-Path $script:Current 'api')
  try { Invoke-Native { & $php -c $ini artisan @arguments } } finally { Pop-Location }
}

function Get-VsTasks { Get-ScheduledTask -TaskName "$($script:TaskPrefix)*" -ErrorAction SilentlyContinue }

function Register-VsTask([string]$Name, [string]$Exe, [string]$Arguments, [string]$WorkDir, [int]$DelaySeconds = 0) {
  $task = "$($script:TaskPrefix)$Name"
  Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue
  $actionArgs = @{ Execute = $Exe; WorkingDirectory = $WorkDir }
  if ($Arguments) { $actionArgs.Argument = $Arguments }
  $action = New-ScheduledTaskAction @actionArgs
  $trigger = New-ScheduledTaskTrigger -AtStartup
  if ($DelaySeconds -gt 0) { $trigger.Delay = "PT$($DelaySeconds)S" }
  $settings = New-ScheduledTaskSettingsSet -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
  Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
}

function Start-VsService([string]$Pattern) {
  Get-VsTasks | Where-Object { $_.TaskName -like "$($script:TaskPrefix)$Pattern" } | ForEach-Object { Start-ScheduledTask -TaskName $_.TaskName }
}

function Stop-VsService([string]$Pattern) {
  foreach ($t in (Get-VsTasks | Where-Object { $_.TaskName -like "$($script:TaskPrefix)$Pattern" })) {
    if ($t.TaskName -eq "$($script:TaskPrefix)mariadb") { Stop-Mariadb } else { Stop-ScheduledTask -TaskName $t.TaskName -ErrorAction SilentlyContinue }
  }
}

function Stop-Mariadb {
  $envMap = Read-EnvFile
  $admin = Join-Path $script:Current 'mariadb\bin\mariadb-admin.exe'
  if ((Test-Path $admin) -and $envMap.DB_PORT) {
    Invoke-Native { & $admin --host=127.0.0.1 --port=$($envMap.DB_PORT) -uroot "-p$($envMap.DB_ROOT_PASSWORD)" shutdown } | Out-Null
  }
  Stop-ScheduledTask -TaskName "$($script:TaskPrefix)mariadb" -ErrorAction SilentlyContinue
}

function Stop-AllVs {
  foreach ($s in 'proxy', 'web', 'scheduler', 'queue', 'php-*', 'redis', 'mariadb') { Stop-VsService $s }
}

function Start-AllVs {
  foreach ($s in 'mariadb', 'redis') { Start-VsService $s }
  Wait-Port ([int](Read-EnvFile).DB_PORT) 90 | Out-Null
  foreach ($s in 'php-*', 'queue', 'scheduler', 'web', 'proxy') { Start-VsService $s }
}

function Wait-Port([int]$Port, [int]$Seconds = 60) {
  for ($i = 0; $i -lt $Seconds; $i++) {
    if (-not (Test-PortFree $Port)) { return $true }
    Start-Sleep 1
  }
  $false
}

function Wait-Health([int]$WebPort, [int]$Seconds = 90) {
  for ($i = 0; $i -lt $Seconds; $i++) {
    try {
      $r = Invoke-WebRequest -Uri "http://127.0.0.1:$WebPort/api/v1/health" -UseBasicParsing -TimeoutSec 5
      if ($r.StatusCode -eq 200) { return $true }
    } catch { }
    Start-Sleep 2
  }
  $false
}

# Backup: the database (a SQL dump) and the pictures, in one zip. Returns the path.
function New-VsBackup([string]$OutDir = $script:Backups, [string]$Label = '') {
  $envMap = Read-EnvFile
  New-Item -ItemType Directory -Force $OutDir | Out-Null
  $stamp = Get-Date -Format 'yyyy-MM-dd-HHmmss'
  $work = Join-Path $env:TEMP "vs-backup-$stamp"
  New-Item -ItemType Directory -Force $work | Out-Null
  $dump = Join-Path $work 'database.sql'
  $exe = Join-Path $script:Current 'mariadb\bin\mariadb-dump.exe'
  Invoke-Native { & $exe --host=127.0.0.1 --port=$($envMap.DB_PORT) -uroot "-p$($envMap.DB_ROOT_PASSWORD)" --single-transaction --routines --databases votesystem "--result-file=$dump" } | Out-Null
  if (-not (Test-Path $dump) -or (Get-Item $dump).Length -lt 100) { throw 'the database dump failed' }
  $media = Join-Path $script:DataDir 'media'
  if (Test-Path $media) { Copy-Item $media (Join-Path $work 'media') -Recurse -Force }
  Get-FileHash $dump -Algorithm SHA256 | ForEach-Object { "$($_.Hash.ToLower())  database.sql" } | Set-Content (Join-Path $work 'SHA256SUMS') -Encoding ASCII
  $out = Join-Path $OutDir ("votesystem-$stamp$Label.zip")
  Compress-Archive -Path (Join-Path $work '*') -DestinationPath $out -Force
  Remove-Item $work -Recurse -Force
  $out
}

function Invoke-VsRestore([string]$File) {
  if (-not (Test-Path $File)) { throw "file not found: $File" }
  $envMap = Read-EnvFile
  $work = Join-Path $env:TEMP "vs-restore-$([guid]::NewGuid().ToString('N'))"
  Expand-Archive -Path $File -DestinationPath $work -Force
  $dump = Join-Path $work 'database.sql'
  if (-not (Test-Path $dump)) { throw 'the archive has no database.sql' }
  $sums = Join-Path $work 'SHA256SUMS'
  if (Test-Path $sums) {
    $want = ((Get-Content $sums | Select-Object -First 1) -split '\s+')[0]
    $have = (Get-FileHash $dump -Algorithm SHA256).Hash.ToLower()
    if ($want -ne $have) { throw 'the database dump does not match its checksum' }
  }
  $client = Join-Path $script:Current 'mariadb\bin\mariadb.exe'
  $sql = Get-Content $dump -Raw -Encoding UTF8
  Invoke-Native { $sql | & $client --host=127.0.0.1 --port=$($envMap.DB_PORT) -uroot "-p$($envMap.DB_ROOT_PASSWORD)" } | Out-Null
  $media = Join-Path $work 'media'
  if (Test-Path $media) { Copy-Item (Join-Path $media '*') (Join-Path $script:DataDir 'media') -Recurse -Force }
  Remove-Item $work -Recurse -Force
}
