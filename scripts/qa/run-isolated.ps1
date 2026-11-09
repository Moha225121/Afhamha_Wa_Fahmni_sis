param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $TestArguments = @()
)

$ErrorActionPreference = 'Stop'
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaRun = Join-Path $qaRoot ('tmp/qa-runs/' + [Guid]::NewGuid().ToString('N'))
$qaStorage = Join-Path $qaRun 'storage'
foreach ($relative in @('framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'app/public')) {
    New-Item -ItemType Directory -Path (Join-Path $qaStorage $relative) -Force | Out-Null
}
$qaEnvironment = @{
    APP_ENV = 'testing'
    APP_KEY = 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI='
    APP_URL = 'http://127.0.0.1'
    APP_CONFIG_CACHE = ('tmp/qa-runs/' + (Split-Path $qaRun -Leaf) + '/unused-config.php')
    APP_ROUTES_CACHE = ('tmp/qa-runs/' + (Split-Path $qaRun -Leaf) + '/unused-routes.php')
    LARAVEL_STORAGE_PATH = $qaStorage
    VIEW_COMPILED_PATH = (Join-Path $qaStorage 'framework/views')
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = ':memory:'
    DB_URL = ''
    MAIL_MAILER = 'array'
    CACHE_STORE = 'array'
    SESSION_DRIVER = 'array'
    QUEUE_CONNECTION = 'sync'
    DB_QUEUE_CONNECTION = 'sqlite'
    FILESYSTEM_DISK = 'local'
    LOG_CHANNEL = 'single'
    GEMINI_API_KEY = ''
    FIREBASE_PROJECT_ID = ''
    GOOGLE_APPLICATION_CREDENTIALS = ''
    VAPID_PUBLIC_KEY = ''
    VAPID_PRIVATE_KEY = ''
}
$qaPrevious = @{}
try {
    foreach ($key in $qaEnvironment.Keys) {
        $qaPrevious[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $qaEnvironment[$key], 'Process')
    }
    Push-Location $qaRoot
    Write-Host 'QA database: isolated SQLite :memory:; uploads and logs: fresh tmp/qa-runs directory.'
    Write-Host "QA output directory: $qaRun"
    # Invoke the binary directly: some Windows php.bat wrappers re-interpret
    # PHPUnit filter pipes as shell pipelines.
    $qaPhpBinary = (& php -r 'echo PHP_BINARY;').Trim()
    if (-not (Test-Path -LiteralPath $qaPhpBinary -PathType Leaf)) {
        throw 'Unable to resolve the PHP executable.'
    }
    & $qaPhpBinary -d memory_limit=512M vendor/phpunit/phpunit/phpunit --log-junit (Join-Path $qaRun 'phpunit.xml') @TestArguments
    $qaExit = $LASTEXITCODE
} finally {
    Pop-Location
    foreach ($key in $qaPrevious.Keys) {
        [Environment]::SetEnvironmentVariable($key, $qaPrevious[$key], 'Process')
    }
}
exit $qaExit
