$ErrorActionPreference = 'Stop'

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

    Write-Host 'PHP was not found. Install PHP 8.3+ or add it to PATH.'
    exit 1
}

Set-Location $PSScriptRoot

$php = Resolve-PhpExecutable

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
