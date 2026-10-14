<#
VoteSystem, school edition, Windows installer (Windows 10 1809 or later, Windows 11, Windows Server 2019 or later, x64).

  powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 [install] [options]   first installation
  powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 update                 put this bundle in place of the installed version
  powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 uninstall [-PurgeData]

Run it in a PowerShell window opened "as administrator". No Docker, no WSL: the services run natively and start with the
computer. Options of install:
  -Yes                 answer every question with its default (a script's run)
  -DryRun              checks, ports and the plan; changes nothing
  -WebPort N           the port of the site (default 80; the next free one is taken when it is busy)
  -School NAME         the name of the school (default "Mon école")
  -OwnerEmail ADDRESS  the school's owner sign-in address (default directeur@ecole.local)
  -AdminEmail ADDRESS  the platform admin's sign-in address (default admin@ecole.local)
  -CredentialsFile F   where the accounts are written (default: the Desktop of the person who runs this)
  -NoFirewall          do not add the Windows Firewall rule
  -Workers N           PHP workers (default 4)
  -Lang fr|en          the language of this script's messages
#>
param(
  [Parameter(Position = 0)][ValidateSet('install', 'update', 'uninstall')][string]$Command = 'install',
  [switch]$Yes,
  [switch]$DryRun,
  [int]$WebPort = 80,
  [string]$School = '',
  [string]$OwnerEmail = 'directeur@ecole.local',
  [string]$AdminEmail = 'admin@ecole.local',
  [string]$CredentialsFile = '',
  [switch]$NoFirewall,
  [switch]$PurgeData,
  [int]$Workers = 4,
  [ValidateSet('fr', 'en', '')][string]$Lang = ''
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8

$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $Here 'lib\common.ps1')
$Bundle = Split-Path -Parent $Here

if (-not $Lang) { $Lang = if ((Get-Culture).TwoLetterISOLanguageName -eq 'fr') { 'fr' } else { 'en' } }
function Say([string]$fr, [string]$en) { if ($Lang -eq 'fr') { Write-Host $fr } else { Write-Host $en } }
function Fail([string]$fr, [string]$en) { Say "ERREUR : $fr" "ERROR: $en"; exit 1 }
function Write-Utf8([string]$Path, [string]$Text) {
  New-Item -ItemType Directory -Force (Split-Path -Parent $Path) | Out-Null
  [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false)))
}
function Ask([string]$fr, [string]$en, [string]$default) {
  if ($Yes) { return $default }
  $answer = Read-Host ("{0} [{1}]" -f $(if ($Lang -eq 'fr') { $fr } else { $en }), $default)
  if ([string]::IsNullOrWhiteSpace($answer)) { $default } else { $answer.Trim() }
}
function Ask-YesNo([string]$fr, [string]$en) {
  if ($Yes) { return $true }
  $answer = Read-Host ("{0} [O/n]" -f $(if ($Lang -eq 'fr') { $fr } else { $en }))
  $answer -eq '' -or $answer -match '^(o|y)'
}
function Render([string]$Template, [hashtable]$Values) {
  $text = Get-Content $Template -Raw -Encoding UTF8
  foreach ($k in $Values.Keys) { $text = $text.Replace("@$k@", [string]$Values[$k]) }
  $text
}
function Slash([string]$p) { $p -replace '\\', '/' }

function Test-Admin {
  ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# ------------------------------------------------------------------------------------------------ uninstall
function Invoke-Uninstall {
  if (-not (Test-Path $script:Prefix) -and -not (Get-VsTasks)) { Fail 'aucune installation trouvée' 'no installation found' }
  Say '==> Arrêt et retrait des services' '==> Stopping and removing the services'
  Stop-AllVs
  Start-Sleep 2
  Get-Process -Name caddy, node, php-cgi, mariadbd, redis-server -ErrorAction SilentlyContinue |
    Where-Object { $_.Path -and $_.Path.StartsWith($script:Prefix, [StringComparison]::OrdinalIgnoreCase) } | Stop-Process -Force -ErrorAction SilentlyContinue
  Get-VsTasks | ForEach-Object { Unregister-ScheduledTask -TaskName $_.TaskName -Confirm:$false }
  Get-NetFirewallRule -DisplayName $script:FirewallName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
  $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
  $bin = Join-Path $script:Prefix 'bin'
  if ($machinePath -and $machinePath.Split(';') -contains $bin) {
    [Environment]::SetEnvironmentVariable('Path', (($machinePath.Split(';') | Where-Object { $_ -ne $bin }) -join ';'), 'Machine')
  }
  if (Test-Path $script:Current) { cmd /c rmdir "$($script:Current)" | Out-Null }
  foreach ($release in (Get-ChildItem (Join-Path $script:Prefix 'releases') -Directory -ErrorAction SilentlyContinue)) {
    foreach ($link in 'api\storage', 'api\bootstrap\cache') {
      $p = Join-Path $release.FullName $link
      if (Test-Path $p) { cmd /c rmdir "$p" | Out-Null }
    }
  }
  Remove-Item $script:Prefix -Recurse -Force -ErrorAction SilentlyContinue
  if ($PurgeData) {
    Remove-Item $script:DataDir -Recurse -Force -ErrorAction SilentlyContinue
    Say 'Les données ont aussi été supprimées.' 'The data was deleted as well.'
  } else {
    Say "Les données sont conservées dans $($script:DataDir)." "The data is kept in $($script:DataDir)."
  }
  Say 'VoteSystem est désinstallé.' 'VoteSystem is uninstalled.'
}

# ------------------------------------------------------------------------------------------------ shared steps
function Copy-Bundle([string]$Version) {
  $target = Join-Path (Join-Path $script:Prefix 'releases') $Version
  New-Item -ItemType Directory -Force $target | Out-Null
  & robocopy $Bundle $target /E /NFL /NDL /NJH /NJS /NP /XD (Join-Path $Bundle 'installers') | Out-Null
  if ($LASTEXITCODE -ge 8) { throw "robocopy failed ($LASTEXITCODE)" }
  # What the application writes lives outside the release, so a release can be replaced as a whole.
  $storage = Join-Path $script:DataDir 'storage'
  if (-not (Test-Path (Join-Path $storage 'framework'))) {
    New-Item -ItemType Directory -Force $storage | Out-Null
    & robocopy (Join-Path $target 'api\storage') $storage /E /NFL /NDL /NJH /NJS /NP | Out-Null
  }
  foreach ($d in 'api\storage', 'api\bootstrap\cache') {
    $p = Join-Path $target $d
    if (Test-Path $p) { Remove-Item $p -Recurse -Force }
  }
  New-Item -ItemType Junction -Path (Join-Path $target 'api\storage') -Target $storage | Out-Null
  $cache = Join-Path $script:DataDir 'bootstrap-cache'
  New-Item -ItemType Directory -Force $cache | Out-Null
  New-Item -ItemType Junction -Path (Join-Path $target 'api\bootstrap\cache') -Target $cache | Out-Null
  Copy-Item $script:EnvFile (Join-Path $target 'api\.env') -Force
  $target
}

function Switch-Release([string]$Target) {
  if (Test-Path $script:Current) { cmd /c rmdir "$($script:Current)" | Out-Null }
  New-Item -ItemType Junction -Path $script:Current -Target $Target | Out-Null
}

function Install-Installer {
  # The installer and votectl stay on the machine, for updates and for the uninstall.
  $dest = Join-Path $script:Prefix 'installer'
  New-Item -ItemType Directory -Force $dest | Out-Null
  & robocopy $Here $dest /E /NFL /NDL /NJH /NJS /NP | Out-Null
  $bin = Join-Path $script:Prefix 'bin'
  New-Item -ItemType Directory -Force $bin | Out-Null
  Set-Content (Join-Path $bin 'votectl.cmd') "@echo off`r`npowershell -NoProfile -ExecutionPolicy Bypass -File `"$dest\votectl.ps1`" %*`r`n" -Encoding ASCII
  $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
  if (-not ($machinePath.Split(';') -contains $bin)) {
    [Environment]::SetEnvironmentVariable('Path', "$machinePath;$bin", 'Machine')
  }
}

function Write-PhpIni {
  $php = Join-Path $script:Current 'php'
  $ini = Join-Path $script:ConfigDir 'php.ini'
  $text = Get-Content (Join-Path $Bundle 'php\php.ini-production') -Raw
  $text = $text -replace ';\s*extension_dir\s*=\s*"ext"', "extension_dir = `"$($php)\ext`""
  foreach ($e in 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'zip', 'exif', 'bcmath', 'sockets') {
    $text = $text -replace "(?m)^;\s*extension=$e\s*$", "extension=$e"
  }
  $text = $text -replace '(?m)^;\s*zend_extension\s*=\s*opcache\s*$', 'zend_extension=opcache'
  $text = $text -replace '(?m)^;?\s*opcache\.enable\s*=.*$', 'opcache.enable=1'
  $text = $text -replace '(?m)^;?\s*memory_limit\s*=.*$', 'memory_limit = 256M'
  $text = $text -replace '(?m)^;?\s*upload_max_filesize\s*=.*$', 'upload_max_filesize = 8M'
  $text = $text -replace '(?m)^;?\s*post_max_size\s*=.*$', 'post_max_size = 10M'
  $text = $text -replace '(?m)^;?\s*date\.timezone\s*=.*$', 'date.timezone = UTC'
  Write-Utf8 $ini $text
  # Next to php.exe too: the commands the scheduler starts by themselves (`php artisan ...`) read it there.
  Copy-Item $ini (Join-Path $php 'php.ini') -Force
}

function Register-Services([hashtable]$Ports) {
  $cur = $script:Current
  $api = Join-Path $cur 'api'
  $ini = Join-Path $script:ConfigDir 'php.ini'
  $php = Join-Path $cur 'php\php.exe'
  Register-VsTask 'mariadb' (Join-Path $cur 'mariadb\bin\mariadbd.exe') "--defaults-file=`"$($script:ConfigDir)\mariadb.ini`"" (Join-Path $cur 'mariadb')
  Register-VsTask 'redis' (Join-Path $cur 'redis\redis-server.exe') "`"$($script:ConfigDir)\redis.conf`"" (Join-Path $cur 'redis')
  foreach ($p in $Ports.Php) {
    Register-VsTask "php-$p" (Join-Path $cur 'php\php-cgi.exe') "-c `"$ini`" -b 127.0.0.1:$p" (Join-Path $api 'public') 15
  }
  Register-VsTask 'queue' $php "-c `"$ini`" artisan queue:work --sleep=3 --tries=3 --max-time=3600" $api 20
  Register-VsTask 'scheduler' $php "-c `"$ini`" artisan schedule:work" $api 20
  $webCmd = Join-Path $script:ConfigDir 'web.cmd'
  $cmd = "@echo off`r`nset NODE_ENV=production`r`nset NEXT_TELEMETRY_DISABLED=1`r`nset PORT=$($Ports.WebInternal)`r`nset HOSTNAME=127.0.0.1`r`nset DEPLOYMENT_MODE=school`r`nset API_INTERNAL_URL=http://127.0.0.1:$($Ports.Web)`r`ncd /d `"$($cur)\web`"`r`n`"$($cur)\node\node.exe`" server.js`r`n"
  Set-Content $webCmd $cmd -Encoding ASCII
  Register-VsTask 'web' 'cmd.exe' "/c `"$webCmd`"" (Join-Path $cur 'web') 20
  Register-VsTask 'proxy' (Join-Path $cur 'caddy\caddy.exe') "run --config `"$($script:ConfigDir)\Caddyfile`" --adapter caddyfile" (Join-Path $cur 'caddy') 25
  $backupScript = Join-Path $script:Prefix 'installer\votectl.ps1'
  $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$backupScript`" backup"
  Unregister-ScheduledTask -TaskName "$($script:TaskPrefix)backup" -Confirm:$false -ErrorAction SilentlyContinue
  Register-ScheduledTask -TaskName "$($script:TaskPrefix)backup" -Action $action -Trigger (New-ScheduledTaskTrigger -Daily -At 2:30) `
    -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
}

function Write-Configs([hashtable]$Ports, [hashtable]$Secrets) {
  $dataSlash = Slash $script:DataDir
  Write-Utf8 (Join-Path $script:ConfigDir 'mariadb.ini') (Render (Join-Path $Here 'templates\mariadb.ini.tmpl') @{ DB_PORT = $Ports.Db; DATA_DIR = $dataSlash })
  Write-Utf8 (Join-Path $script:ConfigDir 'redis.conf') (Render (Join-Path $Here 'templates\redis.conf.tmpl') @{ REDIS_PORT = $Ports.Redis; REDIS_PASSWORD = $Secrets.redis })
  $upstreams = ($Ports.Php | ForEach-Object { "127.0.0.1:$_" }) -join ' '
  Write-Utf8 (Join-Path $script:ConfigDir 'Caddyfile') (Render (Join-Path $Here 'templates\Caddyfile.tmpl') @{
      WEB_PORT = $Ports.Web; DATA_DIR = $dataSlash; API_PUBLIC = (Slash (Join-Path $script:Current 'api\public'))
      UPSTREAMS = $upstreams; WEB_INTERNAL_PORT = $Ports.WebInternal
    })
}

function Write-EnvFile([hashtable]$Ports, [hashtable]$Secrets, [string]$SiteUrl) {
  $lines = @(
    '# VoteSystem (school edition, Windows). Written by the installer; secrets: keep this file private.',
    'DEPLOYMENT_MODE=school',
    "APP_NAME=`"$($School -replace '"', '')`"",
    'APP_ENV=production', 'APP_DEBUG=false',
    "APP_URL=$SiteUrl", "SITE_URL=$SiteUrl",
    "APP_KEY=$($Secrets.appKey)",
    "VOTE_AUDIT_PUBLIC_KEY=$($Secrets.auditPublic)",
    "CREDENTIAL_HASH_KEY=$($Secrets.hashKey)",
    'LOG_CHANNEL=stderr', 'LOG_LEVEL=info',
    'SESSION_SECURE_COOKIE=false', 'SESSION_COOKIE=votesystem_session',
    'MAIL_MAILER=log', 'MAIL_FROM_ADDRESS=no-reply@localhost',
    'DB_HOST=127.0.0.1', "DB_PORT=$($Ports.Db)", 'DB_DATABASE=votesystem',
    'DB_USERNAME=app', "DB_PASSWORD=$($Secrets.dbApp)",
    'DB_APP_USERNAME=app', 'DB_MIGRATOR_USERNAME=migrator', "DB_MIGRATOR_PASSWORD=$($Secrets.dbMigrator)",
    "DB_ROOT_PASSWORD=$($Secrets.dbRoot)",
    'REDIS_HOST=127.0.0.1', "REDIS_PORT=$($Ports.Redis)", "REDIS_PASSWORD=$($Secrets.redis)",
    "MEDIA_ROOT=$(Slash (Join-Path $script:DataDir 'media'))",
    "WEB_PORT=$($Ports.Web)", "WEB_INTERNAL_PORT=$($Ports.WebInternal)",
    "API_INTERNAL_URL=http://127.0.0.1:$($Ports.Web)"
  )
  Write-Utf8 $script:EnvFile (($lines -join "`r`n") + "`r`n")
  # Only the system and the administrators read it.
  Invoke-Native { & icacls $script:EnvFile /inheritance:r /grant:r '*S-1-5-18:(F)' '*S-1-5-32-544:(F)' } | Out-Null
}

function Invoke-Migrate {
  $map = Read-EnvFile
  $env:DB_USERNAME = $map.DB_MIGRATOR_USERNAME
  $env:DB_PASSWORD = $map.DB_MIGRATOR_PASSWORD
  try {
    Invoke-Artisan package:discover | Out-Null
    $out = Invoke-Artisan migrate --force
    if ($LASTEXITCODE -ne 0) { throw "migrate failed: $($out | Select-Object -Last 5 | Out-String)" }
    $out = Invoke-Artisan db:grant-app
    if ($LASTEXITCODE -ne 0) { throw "db:grant-app failed: $($out | Select-Object -Last 5 | Out-String)" }
  } finally {
    Remove-Item Env:\DB_USERNAME, Env:\DB_PASSWORD -ErrorAction SilentlyContinue
  }
}

# ------------------------------------------------------------------------------------------------ update
function Invoke-Update {
  if (-not (Test-Path $script:Current)) { Fail 'aucune installation à mettre à jour' 'nothing installed to update' }
  $new = (Get-Content (Join-Path $Bundle 'VERSION') -Raw).Trim()
  $old = Get-Release
  if ($new -eq $old) { Fail "la version $new est déjà installée" "version $new is already installed" }
  Say "==> Mise à jour $old -> $new" "==> Updating $old -> $new"
  if ($DryRun) { Say 'Essai à blanc : rien n''est modifié.' 'Dry run: nothing changed.'; return }

  Say '==> Sauvegarde' '==> Backup'
  $backup = New-VsBackup -Label '-avant-mise-a-jour'
  Say "    $backup" "    $backup"

  $oldTarget = (Get-Item $script:Current).Target
  Stop-VsService 'proxy'; Stop-VsService 'web'; Stop-VsService 'scheduler'; Stop-VsService 'queue'; Stop-VsService 'php-*'
  $target = Copy-Bundle $new
  Switch-Release $target
  Write-PhpIni
  $map = Read-EnvFile
  Write-Configs @{ Web = [int]$map.WEB_PORT; WebInternal = [int]$map.WEB_INTERNAL_PORT; Db = [int]$map.DB_PORT; Redis = [int]$map.REDIS_PORT; Php = @(Get-VsTasks | Where-Object { $_.TaskName -like '*php-*' } | ForEach-Object { [int]($_.TaskName -replace '.*php-', '') }) } @{ redis = $map.REDIS_PASSWORD }
  try {
    Invoke-Migrate
    Register-Services @{ Web = [int]$map.WEB_PORT; WebInternal = [int]$map.WEB_INTERNAL_PORT; Db = [int]$map.DB_PORT; Redis = [int]$map.REDIS_PORT; Php = @(Get-VsTasks | Where-Object { $_.TaskName -like '*php-*' } | ForEach-Object { [int]($_.TaskName -replace '.*php-', '') }) }
    Install-Installer
    Start-AllVs
    if (-not (Wait-Health ([int]$map.WEB_PORT) 120)) { throw 'the site does not answer after the update' }
  } catch {
    Say "ÉCHEC : $_" "FAILED: $_"
    Say '==> Retour à la version précédente' '==> Going back to the previous version'
    Stop-AllVs
    Switch-Release $oldTarget
    Start-AllVs
    Say "La version $old est remise en service. La sauvegarde : $backup" "Version $old is back in service. The backup: $backup"
    exit 1
  }
  Say "VoteSystem est à jour : version $new." "VoteSystem is up to date: version $new."
}

# ------------------------------------------------------------------------------------------------ install
function Invoke-Install {
  Say "VoteSystem, édition école, Windows. Version $((Get-Content (Join-Path $Bundle 'VERSION') -Raw).Trim())" "VoteSystem, school edition, Windows. Version $((Get-Content (Join-Path $Bundle 'VERSION') -Raw).Trim())"
  $version = (Get-Content (Join-Path $Bundle 'VERSION') -Raw).Trim()

  # ---- checks
  Say '==> Vérifications' '==> Checks'
  $os = Get-CimInstance Win32_OperatingSystem
  if ([Environment]::Is64BitOperatingSystem -eq $false) { Fail 'Windows 64 bits requis' '64-bit Windows required' }
  if ([int]$os.BuildNumber -lt 17763) { Fail 'Windows 10 1809 ou plus récent requis' 'Windows 10 1809 or later required' }
  $ramGb = [math]::Round($os.TotalVisibleMemorySize / 1MB, 1)
  $driveName = (Split-Path -Qualifier $script:Prefix).TrimEnd(':')
  $freeGb = [math]::Round((Get-PSDrive $driveName).Free / 1GB, 1)
  Say "    Windows $($os.Caption) $($os.Version), mémoire $ramGb Go, libre $freeGb Go" "    Windows $($os.Caption) $($os.Version), memory $ramGb GB, free $freeGb GB"
  if ($ramGb -lt 3.5) { Fail 'au moins 4 Go de mémoire sont nécessaires' 'at least 4 GB of memory are required' }
  if ($freeGb -lt 5) { Fail 'au moins 5 Go libres sont nécessaires' 'at least 5 GB free are required' }
  foreach ($need in 'php\php.exe', 'php\php-cgi.exe', 'mariadb\bin\mariadbd.exe', 'redis\redis-server.exe', 'node\node.exe', 'caddy\caddy.exe', 'api\artisan', 'web\server.js') {
    if (-not (Test-Path (Join-Path $Bundle $need))) { Fail "fichier manquant dans le paquet : $need" "file missing from the bundle: $need" }
  }
  if (Test-Path $script:Current) { Fail 'VoteSystem est déjà installé (utilisez update ou uninstall)' 'VoteSystem is already installed (use update or uninstall)' }

  # ---- ports
  $webPort = Find-FreePort $WebPort
  if ($webPort -ne $WebPort) { Say "    Le port $WebPort est occupé : le site utilisera le port $webPort." "    Port $WebPort is busy: the site will use port $webPort." }
  $taken = @($webPort)
  $dbPort = Find-FreePort 3307 $taken; $taken += $dbPort
  $redisPort = Find-FreePort 6380 $taken; $taken += $redisPort
  $webInternal = Find-FreePort 3000 $taken; $taken += $webInternal
  $php = @()
  $next = 9001
  for ($i = 0; $i -lt $Workers; $i++) { $p = Find-FreePort $next $taken; $php += $p; $taken += $p; $next = $p + 1 }
  $ports = @{ Web = $webPort; Db = $dbPort; Redis = $redisPort; WebInternal = $webInternal; Php = $php }

  # ---- questions
  if (-not $School) { $School = Ask "Nom de l'école" 'School name' 'Mon école' }
  if (-not $PSBoundParameters.ContainsKey('OwnerEmail')) { $OwnerEmail = Ask 'Adresse de connexion du responsable' 'Owner sign-in address' $OwnerEmail }
  if (-not $PSBoundParameters.ContainsKey('AdminEmail')) { $AdminEmail = Ask 'Adresse de connexion de l''administrateur' 'Administrator sign-in address' $AdminEmail }
  if (-not $CredentialsFile) { $CredentialsFile = Join-Path ([Environment]::GetFolderPath('Desktop')) 'votesystem-identifiants.txt' }
  $keyFile = Join-Path (Split-Path -Parent $CredentialsFile) 'votesystem-cle-audit-A-EMPORTER.txt'
  $openFirewall = (-not $NoFirewall) -and (Ask-YesNo "Ouvrir le port $webPort dans le pare-feu Windows (réseau privé) ?" "Open port $webPort in the Windows Firewall (private network)?")

  $lan = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -match '^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' } | Select-Object -First 1).IPAddress
  $siteHost = if ($lan) { $lan } else { $env:COMPUTERNAME }
  $siteUrl = if ($webPort -eq 80) { "http://$siteHost" } else { "http://$($siteHost):$webPort" }

  Say '==> Plan' '==> Plan'
  Say "    école : $School" "    school: $School"
  Say "    responsable : $OwnerEmail   administrateur : $AdminEmail" "    owner: $OwnerEmail   administrator: $AdminEmail"
  Say "    adresse du site : $siteUrl" "    site address: $siteUrl"
  Say "    ports : site $webPort, base $dbPort, cache $redisPort, web $webInternal, PHP $($php -join ',')" "    ports: site $webPort, database $dbPort, cache $redisPort, web $webInternal, PHP $($php -join ',')"
  Say "    programme : $($script:Prefix)   données : $($script:DataDir)" "    program: $($script:Prefix)   data: $($script:DataDir)"
  Say "    comptes écrits dans : $CredentialsFile" "    accounts written to: $CredentialsFile"
  if ($DryRun) { Say 'Essai à blanc : rien n''est modifié.' 'Dry run: nothing changed.'; return }

  # ---- runtime of Visual C++ (PHP, MariaDB)
  $vcKey = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64'
  $vcOk = (Test-Path $vcKey) -and ((Get-ItemProperty $vcKey).Installed -eq 1)
  if (-not $vcOk) {
    $vc = Join-Path $Bundle 'vc_redist.x64.exe'
    if (-not (Test-Path $vc)) { Fail 'le composant Visual C++ manque (vc_redist.x64.exe)' 'the Visual C++ runtime is missing (vc_redist.x64.exe)' }
    Say '==> Composant Microsoft Visual C++' '==> Microsoft Visual C++ runtime'
    $p = Start-Process $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    if ($p.ExitCode -notin 0, 1638, 3010) { Fail "installation du composant Visual C++ ($($p.ExitCode))" "Visual C++ runtime install ($($p.ExitCode))" }
  }

  # ---- files
  Say '==> Copie des fichiers' '==> Copying the files'
  foreach ($d in $script:Prefix, $script:DataDir, $script:ConfigDir, (Join-Path $script:DataDir 'media'), $script:Backups, (Join-Path $script:DataDir 'mariadb')) {
    New-Item -ItemType Directory -Force $d | Out-Null
  }
  # The DB data folder is created by mariadb-install-db.
  Remove-Item (Join-Path $script:DataDir 'mariadb') -Recurse -Force -ErrorAction SilentlyContinue
  $secrets = @{
    dbRoot = New-Hex 16; dbApp = New-Hex 16; dbMigrator = New-Hex 16; redis = New-Hex 16
    hashKey = New-Hex 32; appKey = ''; auditPublic = ''; auditPrivate = ''
  }
  # Keys, from a PHP file (the bundle's own PHP).
  $keysPhp = Join-Path $env:TEMP 'vs-keys.php'
  Set-Content $keysPhp '<?php $k = sodium_crypto_box_keypair(); echo "base64:" . base64_encode(random_bytes(32)), " ", bin2hex(sodium_crypto_box_publickey($k)), " ", bin2hex(sodium_crypto_box_secretkey($k));' -Encoding ASCII
  $bootIni = Join-Path $env:TEMP 'vs-boot.ini'
  Set-Content $bootIni "extension_dir=`"$(Join-Path $Bundle 'php\ext')`"`r`nextension=sodium`r`n" -Encoding ASCII
  $bundlePhp = Join-Path $Bundle 'php\php.exe'
  $parts = ((Invoke-Native { & $bundlePhp -c $bootIni $keysPhp }) -join '') -split ' '
  Remove-Item $keysPhp, $bootIni -ErrorAction SilentlyContinue
  if ($parts.Count -ne 3) { Fail 'génération des clés' 'key generation' }
  $secrets.appKey = $parts[0]; $secrets.auditPublic = $parts[1]; $secrets.auditPrivate = $parts[2]

  Write-EnvFile $ports $secrets $siteUrl
  $target = Copy-Bundle $version
  Switch-Release $target
  Write-PhpIni
  Write-Configs $ports $secrets
  Install-Installer
  Register-Services $ports

  # ---- database
  Say '==> Base de données' '==> Database'
  $dbData = Join-Path $script:DataDir 'mariadb'
  $installDb = Join-Path $script:Current 'mariadb\bin\mariadb-install-db.exe'
  $install = Invoke-Native { & $installDb --datadir=$dbData "--password=$($secrets.dbRoot)" }
  if ($LASTEXITCODE -ne 0) { Fail "initialisation de la base ($($install | Select-Object -Last 2))" "database init ($($install | Select-Object -Last 2))" }
  Start-VsService 'mariadb'
  if (-not (Wait-Port $dbPort 90)) { Fail 'la base ne démarre pas' 'the database does not start' }
  $sql = @"
CREATE DATABASE IF NOT EXISTS votesystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'app'@'%' IDENTIFIED BY '$($secrets.dbApp)';
CREATE USER IF NOT EXISTS 'migrator'@'%' IDENTIFIED BY '$($secrets.dbMigrator)';
GRANT SELECT ON votesystem.* TO 'app'@'%';
GRANT ALL PRIVILEGES ON votesystem.* TO 'migrator'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
"@
  $client = Join-Path $script:Current 'mariadb\bin\mariadb.exe'
  $out = Invoke-Native { $sql | & $client --host=127.0.0.1 --port=$dbPort -uroot "-p$($secrets.dbRoot)" }
  if ($LASTEXITCODE -ne 0) { Fail "création des comptes de la base ($out)" "database accounts ($out)" }
  Start-VsService 'redis'
  if (-not (Wait-Port $redisPort 30)) { Fail 'le cache ne démarre pas' 'the cache does not start' }

  Say '==> Schéma de la base' '==> Database schema'
  Invoke-Migrate

  # ---- accounts
  Say '==> Comptes' '==> Accounts'
  Write-Utf8 $CredentialsFile ''
  $out = Invoke-Artisan owner:create $OwnerEmail "--institution=$School" "--credentials-file=$CredentialsFile"
  if ($LASTEXITCODE -ne 0) { Fail "compte du responsable ($out)" "owner account ($out)" }
  $env:PLATFORM_ADMIN_PASSWORD = New-Hex 16
  $out = Invoke-Artisan platform:create-admin $AdminEmail --password-from-env=PLATFORM_ADMIN_PASSWORD
  Remove-Item Env:\PLATFORM_ADMIN_PASSWORD -ErrorAction SilentlyContinue
  if ($LASTEXITCODE -ne 0) { Fail "compte de l'administrateur ($out)" "administrator account ($out)" }
  $out = Invoke-Artisan user:reset-password $AdminEmail "--credentials-file=$CredentialsFile"
  if ($LASTEXITCODE -ne 0) { Fail "mot de passe de l'administrateur ($out)" "administrator password ($out)" }
  Invoke-Native { & icacls $CredentialsFile /inheritance:r /grant:r '*S-1-5-32-544:(F)' '*S-1-5-18:(F)' } | Out-Null
  $keyText = "VoteSystem - cle privee de l'audit / audit private key`r`n" +
    "A COPIER sur une cle USB ou un autre ordinateur, puis SUPPRIMER de cette machine. / COPY to a USB key or another computer, then DELETE from this machine.`r`n$($secrets.auditPrivate)`r`n"
  Write-Utf8 $keyFile $keyText
  Invoke-Native { & icacls $keyFile /inheritance:r /grant:r '*S-1-5-32-544:(F)' '*S-1-5-18:(F)' } | Out-Null

  # ---- start
  Say '==> Démarrage des services' '==> Starting the services'
  Start-AllVs
  if (-not (Wait-Health $webPort 120)) { Fail 'le site ne répond pas : voir « votectl status »' 'the site does not answer: see "votectl status"' }
  if ($openFirewall) {
    Get-NetFirewallRule -DisplayName $script:FirewallName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    New-NetFirewallRule -DisplayName $script:FirewallName -Direction Inbound -Protocol TCP -LocalPort $webPort -Action Allow -Profile Private, Domain | Out-Null
    Say "    Règle du pare-feu ajoutée : port $webPort." "    Firewall rule added: port $webPort."
  }
  Set-Content (Join-Path $script:DataDir 'VERSION') $version -Encoding ASCII

  Say '' ''
  Say "VoteSystem est installé : $siteUrl" "VoteSystem is installed: $siteUrl"
  Say "  Comptes : $CredentialsFile" "  Accounts: $CredentialsFile"
  Say "  Clé privée de l'audit : $keyFile" "  Audit private key: $keyFile"
  Say '  Copiez ce dernier fichier sur une clé USB, puis supprimez-le de cet ordinateur.' '  Copy that last file to a USB key, then delete it from this computer.'
  Say '  Au premier accès, chaque compte doit changer son mot de passe provisoire.' '  At first sign-in each account must change its temporary password.'
  Say '  Commande d''exploitation : votectl (ouvrez un nouveau terminal).' '  Operator command: votectl (open a new terminal).'
}

if (-not (Test-Admin)) { Fail 'ouvrez PowerShell « en tant qu''administrateur »' 'open PowerShell "as administrator"' }
switch ($Command) {
  'install' { Invoke-Install }
  'update' { Invoke-Update }
  'uninstall' { Invoke-Uninstall }
}
