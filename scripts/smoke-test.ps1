# Go.js-Lite Smoke Test (v0.5.0)
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts\smoke-test.ps1 -BaseUrl http://127.0.0.1:8080/gojs -Password admin1234 -AutoInstall
# Options:
#   -BaseUrl     Panel base URL including mount prefix, e.g. http://host/gojs
#   -Password    Admin password (used to install if not installed and -AutoInstall)
#   -AutoInstall Auto-install the panel when bootstrap reports "not installed"
# Exit code: 0 = all pass, 1 = any fail

param(
    [string]$BaseUrl = "http://127.0.0.1:8080/gojs",
    [string]$Password = "admin1234",
    [switch]$AutoInstall
)

$ErrorActionPreference = "Stop"
$script:Results = @()
$script:Session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$script:Csrf = ""

function Write-Test($name, $ok, $detail) {
    $script:Results += [PSCustomObject]@{ Name = $name; Ok = $ok; Detail = $detail }
    if ($ok) { Write-Host ("  [PASS] " + $name) -ForegroundColor Green }
    else { Write-Host ("  [FAIL] " + $name + "  " + $detail) -ForegroundColor Red }
}

function Invoke-Api {
    param(
        [string]$Method = "GET",
        [string]$Path,
        [hashtable]$Body = $null,
        [int]$Expect = 200
    )
    $url = $BaseUrl.TrimEnd('/') + "/api/" + $Path.TrimStart('/')
    $params = @{ Uri = $url; Method = $Method; UseBasicParsing = $true; WebSession = $script:Session; TimeoutSec = 30 }
    if ($Body) {
        $json = $Body | ConvertTo-Json -Compress
        $params.Headers = @{ "X-CSRF-Token" = $script:Csrf }
        $params.ContentType = "application/json"
        $params.Body = $json
    }
    try {
        $resp = Invoke-WebRequest @params
        $parsed = $null
        try { $parsed = $resp.Content | ConvertFrom-Json } catch { }
        return @{ Status = [int]$resp.StatusCode; Json = $parsed; Raw = $resp.Content }
    } catch {
        if ($_.Exception.Response) {
            $raw = ""
            try {
                $stream = $_.Exception.Response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $raw = $reader.ReadToEnd()
            } catch { }
            $parsed = $null
            try { $parsed = $raw | ConvertFrom-Json } catch { }
            return @{ Status = [int]$_.Exception.Response.StatusCode; Json = $parsed; Raw = $raw }
        }
        return @{ Status = 0; Json = $null; Raw = $_.Exception.Message }
    }
}

Write-Host "=== Go.js-Lite v0.5.0 Smoke Test ===" -ForegroundColor Cyan
Write-Host ("Base URL: " + $BaseUrl)
Write-Host ""

# 1. Bootstrap
$boot = Invoke-Api -Method "GET" -Path "bootstrap"
Write-Test "bootstrap reachable" ($boot.Status -eq 200) ("status=" + $boot.Status)
if ($boot.Status -ne 200) { $boot.Raw }

$installed = $false
if ($boot.Json -and $boot.Json.data) {
    $installed = [bool]$boot.Json.data.installed
    $script:Csrf = [string]$boot.Json.data.csrfToken
}
Write-Host ("  installed=" + $installed)

# 2. Install if needed
if (-not $installed) {
    if (-not $AutoInstall) {
        Write-Test "install (skipped, use -AutoInstall)" $false "panel not installed"
    } else {
        $ins = Invoke-Api -Method "POST" -Path "install" -Body @{ password = $Password }
        Write-Test "install" ($ins.Status -eq 200) ("status=" + $ins.Status + " " + $ins.Raw)
        $boot2 = Invoke-Api -Method "GET" -Path "bootstrap"
        if ($boot2.Json -and $boot2.Json.data) {
            $installed = [bool]$boot2.Json.data.installed
            $script:Csrf = [string]$boot2.Json.data.csrfToken
        }
    }
}

if (-not $installed) {
    Write-Host "Panel not installed, aborting." -ForegroundColor Yellow
    exit 1
}

# 3. Login
$login = Invoke-Api -Method "POST" -Path "login" -Body @{ username = "admin"; password = $Password }
$loggedIn = ($login.Status -eq 200 -and $login.Json -and $login.Json.data -and $login.Json.data.authenticated)
Write-Test "login" $loggedIn ("status=" + $login.Status)
if ($login.Json -and $login.Json.data) { $script:Csrf = [string]$login.Json.data.csrfToken }

if (-not $loggedIn) {
    Write-Host "Login failed, aborting." -ForegroundColor Yellow
    exit 1
}

# 4. REST endpoints (must return 200 with ok=true)
$restEndpoints = @(
    "dashboard",
    "files",
    "settings",
    "htaccess",
    "backup/runs",
    "api-tokens",
    "backup/destinations",
    "backup/schedules",
    "ftp/accounts",
    "trash",
    "ssl/certificates",
    "auth/totp/status",
    "secscan/frontend"
)
Write-Host ""
Write-Host "REST endpoints:" -ForegroundColor Cyan
foreach ($ep in $restEndpoints) {
    $r = Invoke-Api -Method "GET" -Path $ep
    $ok = $r.Status -eq 200
    if ($ok -and $r.Json) { $ok = $r.Json.ok -eq $true }
    Write-Test ("GET " + $ep) $ok ("status=" + $r.Status)
}

# 5. Legacy aliases (old frontend paths must not 404)
$legacyAliases = @(
    "files/list",
    "settings/get",
    "htaccess/get",
    "trash/list",
    "ssl/acme/certificates",
    "2fa/status"
)
Write-Host ""
Write-Host "Legacy alias endpoints:" -ForegroundColor Cyan
foreach ($ep in $legacyAliases) {
    $r = Invoke-Api -Method "GET" -Path $ep
    $ok = $r.Status -eq 200
    if ($ok -and $r.Json) { $ok = $r.Json.ok -eq $true }
    Write-Test ("GET " + $ep) $ok ("status=" + $r.Status)
}

# 6. Logout then verify session is dead
Write-Host ""
$logout = Invoke-Api -Method "POST" -Path "logout"
Write-Test "logout" ($logout.Status -eq 200) ("status=" + $logout.Status)

$after = Invoke-Api -Method "GET" -Path "dashboard"
Write-Test "dashboard after logout -> 401" ($after.Status -eq 401) ("status=" + $after.Status)

# 7. Summary
Write-Host ""
$passCount = @($script:Results | Where-Object { $_.Ok }).Count
$failCount = @($script:Results | Where-Object { -not $_.Ok }).Count
Write-Host ("=== Result: " + $passCount + " passed, " + $failCount + " failed ===") -ForegroundColor Cyan
if ($failCount -gt 0) { exit 1 }
exit 0
