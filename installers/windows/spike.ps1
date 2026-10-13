<#
 VoteSystem - Windows spike, part 1: look only.
 Reads the machine and prints a report. It changes nothing, installs nothing, opens no port
 and sends nothing anywhere. Run in a normal PowerShell window:

   powershell -ExecutionPolicy Bypass -File .\spike.ps1

 Then send the file spike-report.txt (it is written next to this script).
#>
$ErrorActionPreference = 'Continue'
$report = New-Object System.Collections.Generic.List[string]
function Add-Line([string]$text) { $report.Add($text); Write-Host $text }

Add-Line "VoteSystem Windows spike - $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
Add-Line ''

# System
$os = Get-CimInstance Win32_OperatingSystem
Add-Line "Windows        : $($os.Caption) $($os.Version) build $($os.BuildNumber) ($($os.OSArchitecture))"
$cs = Get-CimInstance Win32_ComputerSystem
Add-Line ("RAM            : {0:N1} GB" -f ($cs.TotalPhysicalMemory / 1GB))
$disk = Get-PSDrive -PSProvider FileSystem | Where-Object { $_.Name -eq 'C' }
if ($disk) { Add-Line ("Free on C:     : {0:N1} GB" -f ($disk.Free / 1GB)) }
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
Add-Line "Administrator   : $admin"
Add-Line "PowerShell      : $($PSVersionTable.PSVersion)"

# Virtualisation (needed only for the WSL2 fallback)
Add-Line "Virtualisation  : $((Get-CimInstance Win32_Processor | Select-Object -First 1).VirtualizationFirmwareEnabled)"
$wsl = Get-Command wsl.exe -ErrorAction SilentlyContinue
Add-Line "WSL available   : $([bool]$wsl)"

# Ports we would like to use
foreach ($port in 80, 443, 8080, 3306, 6379) {
    $used = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue
    Add-Line ("Port {0,-5} used : {1}" -f $port, [bool]$used)
}

# Firewall
try {
    $profiles = Get-NetFirewallProfile | ForEach-Object { "$($_.Name)=$($_.Enabled)" }
    Add-Line "Firewall        : $($profiles -join ', ')"
} catch { Add-Line "Firewall        : could not be read ($($_.Exception.Message))" }

# What is already installed
foreach ($tool in 'php', 'node', 'mysqld', 'mariadbd', 'redis-server', 'caddy', 'rr') {
    $cmd = Get-Command $tool -ErrorAction SilentlyContinue
    Add-Line ("{0,-14} : {1}" -f $tool, $(if ($cmd) { $cmd.Source } else { 'not found' }))
}
$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    Add-Line ''
    Add-Line 'PHP version and modules:'
    & php -v | ForEach-Object { Add-Line "  $_" }
    $need = 'pdo_mysql', 'bcmath', 'opcache', 'gd', 'exif', 'zip', 'sodium', 'mbstring', 'openssl', 'intl', 'sockets'
    $have = (& php -m) | ForEach-Object { $_.Trim().ToLower() }
    foreach ($ext in $need) { Add-Line ("  {0,-10} : {1}" -f $ext, $(if ($have -contains $ext) { 'yes' } else { 'MISSING' })) }
    Add-Line ("  pcntl      : {0} (not expected on Windows)" -f $(if ($have -contains 'pcntl') { 'yes' } else { 'no' }))
}

$path = Join-Path $PSScriptRoot 'spike-report.txt'
$report | Set-Content -Path $path -Encoding UTF8
Write-Host ''
Write-Host "Report written to $path"
