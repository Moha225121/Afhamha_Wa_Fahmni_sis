$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

$preferredPhp = 'C:\php-8.4.12\php.exe'

if (Test-Path $preferredPhp) {
    $php = $preferredPhp
} else {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue

    if (-not $phpCommand) {
        Write-Host 'PHP was not found. Install PHP 8.3+ or update run-tests.ps1 with your PHP path.'
        exit 1
    }

    $php = $phpCommand.Source
}

$phpunit = Join-Path $PSScriptRoot 'vendor\phpunit\phpunit\phpunit'

if (-not (Test-Path $phpunit)) {
    Write-Host 'PHPUnit was not found. Run composer install first, then run this script again.'
    exit 1
}

& $php `
    -d extension=fileinfo `
    -d extension=zip `
    -d extension=pdo_sqlite `
    -d extension=sqlite3 `
    $phpunit `
    @args

exit $LASTEXITCODE
