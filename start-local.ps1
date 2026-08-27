param(
    [switch]$NoBrowser
)

$php = 'C:\php-8.4.12\php.exe'
$hostAddress = '127.0.0.1'
$port = 5500
$loginUrl = "http://${hostAddress}:$port/login"

$existingServer = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
if ($existingServer) {
    $processIds = ($existingServer | Select-Object -ExpandProperty OwningProcess -Unique) -join ', '
    Write-Host "Port $port is already in use by process ID(s): $processIds"
    Write-Host "If the app is already running, open $loginUrl"
    if (-not $NoBrowser) {
        Start-Process $loginUrl
    }
    exit 1
}

Write-Host "Starting Laravel at $loginUrl"
Write-Host "Keep this terminal open while using the app."
Write-Host "Checking local database migrations..."

& $php `
    -d extension=fileinfo `
    -d extension=zip `
    -d extension=pdo_sqlite `
    -d extension=sqlite3 `
    artisan migrate --force

if ($LASTEXITCODE -ne 0) {
    Write-Host "Database migration failed. Fix the migration error above, then run start-local.cmd again."
    exit $LASTEXITCODE
}

if (-not $NoBrowser) {
    Start-Job -ScriptBlock {
        param($url)
        Start-Sleep -Seconds 2
        Start-Process $url
    } -ArgumentList $loginUrl | Out-Null
}

& $php `
    -d extension=fileinfo `
    -d extension=zip `
    -d extension=pdo_sqlite `
    -d extension=sqlite3 `
    -S ${hostAddress}:$port `
    -t public `
    start-local-router.php
