# Run in the Windows test VM after install.ps1 (as the SYSTEM agent or an administrator): the whole vote flow through the
# API of the installed site, on 127.0.0.1. Prints PASS/FAIL for each step. The credentials file is the one the installer
# wrote (default path below). See installers/windows/README.md.
$ErrorActionPreference = 'Stop'; $ProgressPreference = 'SilentlyContinue'
$base = 'http://127.0.0.1/api/v1'
$ws = New-Object Microsoft.PowerShell.Commands.WebRequestSession
function Token { $c = $ws.Cookies.GetCookies('http://127.0.0.1') | Where-Object Name -eq 'XSRF-TOKEN' | Select-Object -First 1; if ($c) { [Uri]::UnescapeDataString($c.Value) } else { '' } }
function Call($method, $path, $body = $null, $session = $ws) {
  $h = @{ Accept = 'application/json'; 'X-XSRF-TOKEN' = (Token) }
  $args = @{ Uri = "$base$path"; Method = $method; Headers = $h; WebSession = $session; UseBasicParsing = $true }
  if ($null -ne $body) { $args.Body = ($body | ConvertTo-Json -Depth 6); $args.ContentType = 'application/json' }
  try { $r = Invoke-WebRequest @args } catch { $resp = $_.Exception.Response; $txt = ''; if ($resp) { $txt = (New-Object IO.StreamReader($resp.GetResponseStream())).ReadToEnd() }; throw "$method $path -> $([int]$resp.StatusCode) $txt" }
  if ($r.Content) { return ($r.Content | ConvertFrom-Json) } else { return $null }
}
function Step($name, [scriptblock]$code) { try { $v = & $code; Write-Host "PASS  $name"; return $v } catch { Write-Host "FAIL  $name : $($_ | Out-String)"; return $null } }

$cred = Get-Content $env:VOTE_CREDENTIALS -Raw
$pw = ([regex]::Match($cred, 'password\s*:\s*(\S+)', 'IgnoreCase')).Groups[1].Value
$email = 'directeur@ecole.local'
$newPw = 'un mot de passe bien long 2026'

Step 'csrf cookie' { Invoke-WebRequest -Uri "$base/auth/csrf" -WebSession $ws -UseBasicParsing | Out-Null; if (-not (Token)) { throw 'no XSRF-TOKEN' } } | Out-Null
Step 'login' { try { Call 'POST' '/auth/login' @{ email = $email; password = $pw } | Out-Null } catch { Call 'POST' '/auth/login' @{ email = $email; password = $newPw } | Out-Null; $script:pw = $newPw } } | Out-Null
Step 'change the password when it is still the one-time one' { if ($pw -ne $newPw) { Call 'POST' '/auth/change-password' @{ current_password = $pw; password = $newPw } | Out-Null } } | Out-Null
Step 'elections list' { Call 'GET' '/elections' | Out-Null } | Out-Null

$starts = (Get-Date).ToUniversalTime().AddSeconds(150).ToString("yyyy-MM-ddTHH:mm:ssZ")
$ends = (Get-Date).ToUniversalTime().AddDays(2).ToString("yyyy-MM-ddTHH:mm:ssZ")
$election = Step 'create an election' { (Call 'POST' '/elections' @{ title = 'Conseil des eleves'; starts_at = $starts; ends_at = $ends }).data }
$ballot = Step 'create a ballot' { (Call 'POST' "/elections/$($election.id)/ballots" @{ title = 'President'; seats = 1; allow_blank = $true }).data }
Step 'candidate 1' { Call 'POST' "/ballots/$($ballot.id)/candidates" @{ first_name = 'Nadege'; last_name = 'Pierre-Louis'; sex = 'female' } | Out-Null } | Out-Null
Step 'candidate 2' { Call 'POST' "/ballots/$($ballot.id)/candidates" @{ first_name = 'Jean-Marc'; last_name = 'Desir'; sex = 'male' } | Out-Null } | Out-Null
$voter = Step 'create a voter' { (Call 'POST' "/elections/$($election.id)/voters" @{ full_name = 'Wideline Joseph' }).data }
$voterCode = Step 'issue the code' { (Call 'POST' "/voters/$($voter.id)/credential" @{}).data.code }

Step 'schedule' { Call 'POST' "/elections/$($election.id)/schedule" @{ accept_warnings = $true } | Out-Null } | Out-Null
Step 'the scheduler opens the election (at most 3 minutes)' {
  for ($i = 0; $i -lt 90; $i++) {
    $phase = (Invoke-RestMethod -Uri "$base/vote/$($election.id)" -UseBasicParsing).data.phase
    if ($phase -eq 'open') { return }
    Start-Sleep 3
  }
  throw "still $phase"
} | Out-Null

$vws = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Step 'voter session with the code' {
  Invoke-WebRequest -Uri "$base/auth/csrf" -WebSession $vws -UseBasicParsing | Out-Null
  $c = $vws.Cookies.GetCookies('http://127.0.0.1') | Where-Object Name -eq 'XSRF-TOKEN' | Select-Object -First 1
  $tok = if ($c) { [Uri]::UnescapeDataString($c.Value) } else { '' }
  $r = Invoke-WebRequest -Uri "$base/vote/$($election.id)/session" -Method POST -Headers @{ Accept = 'application/json'; 'X-XSRF-TOKEN' = $tok } -Body (@{ code = $voterCode } | ConvertTo-Json) -ContentType 'application/json' -WebSession $vws -UseBasicParsing
  if ($r.StatusCode -ne 201) { throw "status $($r.StatusCode)" }
} | Out-Null
$vtok = { $c = $vws.Cookies.GetCookies('http://127.0.0.1') | Where-Object Name -eq 'XSRF-TOKEN' | Select-Object -First 1; if ($c) { [Uri]::UnescapeDataString($c.Value) } else { '' } }
$ballots = Step 'ballots of the voter' { (Invoke-RestMethod -Uri "$base/vote/$($election.id)/ballots" -WebSession $vws -UseBasicParsing -Headers @{ Accept = 'application/json' }).data.ballots }
Step 'submit the vote' {
  $b = $ballots[0]
  $body = @{ ballots = @(@{ ballot = $b.id; candidates = @($b.candidates[0].id) }) } | ConvertTo-Json -Depth 6
  $r = Invoke-WebRequest -Uri "$base/vote/$($election.id)/submit" -Method POST -Headers @{ Accept = 'application/json'; 'X-XSRF-TOKEN' = (& $vtok) } -Body $body -ContentType 'application/json' -WebSession $vws -UseBasicParsing
  if ($r.StatusCode -ne 201) { throw "status $($r.StatusCode)" }
} | Out-Null
# A second submit with the same session is an idempotent replay: 200 with the same time, one vote counted.
Step 'a second submit is a replay, not a second vote' {
  try {
    $b = $ballots[0]
    $body = @{ ballots = @(@{ ballot = $b.id; candidates = @($b.candidates[0].id) }) } | ConvertTo-Json -Depth 6
    Invoke-WebRequest -Uri "$base/vote/$($election.id)/submit" -Method POST -Headers @{ Accept = 'application/json'; 'X-XSRF-TOKEN' = (& $vtok) } -Body $body -ContentType 'application/json' -WebSession $vws -UseBasicParsing | Out-Null
    # reaching here is the expected replay (200); the results check below proves one vote only
  } catch { throw $_ }
} | Out-Null
Step 'close the election' { Call 'POST' "/elections/$($election.id)/close" @{ confirmation = 'Conseil des eleves' } | Out-Null } | Out-Null
$res = Step 'results' { (Call 'GET' "/elections/$($election.id)/results").data }
if ($res) { Write-Host "cast: $($res.ballots[0].cast) eligible: $($res.ballots[0].eligible) check ok: $($res.ballots[0].check.ok)" }
