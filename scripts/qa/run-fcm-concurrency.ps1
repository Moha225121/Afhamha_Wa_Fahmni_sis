param([Parameter(Mandatory = $true)][string] $Marker)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'postgres-common.ps1')
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaCluster = Read-QaPostgresMarker $Marker
$qaState = Set-QaPostgresEnvironment $qaCluster $qaRoot
try {
    Assert-QaPostgresIdentity $qaCluster 'afhamha_testing'
    Push-Location $qaRoot
    $qaPhp = (& php -r 'echo PHP_BINARY;').Trim()
    & $qaPhp -d memory_limit=512M scripts/qa/fcm-postgres-concurrency.php
    $qaExit = $LASTEXITCODE
} finally {
    Pop-Location
    Restore-QaPostgresEnvironment $qaState
}
exit $qaExit
