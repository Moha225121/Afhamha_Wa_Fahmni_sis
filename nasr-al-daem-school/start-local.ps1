param(
    [switch]$NoBrowser
)

function Resolve-PhpExecutable {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        return $phpCommand.Source
    }

    $commonPaths = @(
        'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe',
        'C:\php\php.exe',
        'C:\php-8.4.12\php.exe',
        'C:\Program Files\PHP\php.exe',
        'C:\Program Files (x86)\PHP\php.exe',
        "$env:LOCALAPPDATA\Programs\PHP\php.exe"
    )

    foreach ($path in $commonPaths) {
        if (Test-Path $path) {
            return $path
        }
    }

    throw "PHP 8.3+ was not found. Install PHP or add it to PATH."
}

$php = Resolve-PhpExecutable
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

$artisanPath = Join-Path $PSScriptRoot 'artisan'
$existingScheduler = Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine.Contains($artisanPath) -and $_.CommandLine.Contains('schedule:work') }
$scheduler = $null
if (-not $existingScheduler) {
    $scheduler = Start-Process -FilePath $php -ArgumentList @(('"' + $artisanPath + '"'), 'schedule:work', '--no-interaction') `
        -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $PSScriptRoot 'storage\logs\scheduler-output.log') `
        -RedirectStandardError (Join-Path $PSScriptRoot 'storage\logs\scheduler-error.log')
}
Write-Host "Scheduled result publishing and installment reminders are running with the app."

try {
    & $php `
        -d extension=fileinfo `
        -d extension=zip `
        -S ${hostAddress}:$port `
        -t public `
        start-local-router.php
}
finally {
    if ($scheduler -and -not $scheduler.HasExited) {
        Stop-Process -Id $scheduler.Id -ErrorAction SilentlyContinue
    }
}
