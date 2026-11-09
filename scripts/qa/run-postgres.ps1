param(
    [Parameter(Mandatory = $true)][string] $Marker,
    [string] $SourceRoot = '',
    [Parameter(ValueFromRemainingArguments = $true)][string[]] $TestArguments = @()
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'postgres-common.ps1')
if (!$SourceRoot) { $SourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..')) }
$qaCluster = Read-QaPostgresMarker $Marker
$qaSource = (Resolve-Path -LiteralPath $SourceRoot).Path
$qaState = Set-QaPostgresEnvironment $qaCluster $qaSource
try {
    Assert-QaPostgresIdentity $qaCluster 'afhamha_testing'
    Push-Location $qaSource
    Write-Host "QA PostgreSQL: $($qaCluster.version); 127.0.0.1:$($qaCluster.port)/afhamha_testing; isolated marked cluster."
    Write-Host "QA output directory: $($qaState.output)"
    $qaPhp = (& php -r 'echo PHP_BINARY;').Trim()
    & $qaPhp -d memory_limit=512M vendor/phpunit/phpunit/phpunit --log-junit (Join-Path $qaState.output 'phpunit.xml') @TestArguments
    $qaExit = $LASTEXITCODE
} finally {
    Pop-Location
    Restore-QaPostgresEnvironment $qaState
}
exit $qaExit
