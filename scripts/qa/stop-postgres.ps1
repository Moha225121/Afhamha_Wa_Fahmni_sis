param([Parameter(Mandatory = $true)][string] $Marker)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'postgres-common.ps1')
$qaCluster = Read-QaPostgresMarker $Marker
& (Join-Path $qaCluster.bin 'pg_ctl.exe') -D $qaCluster.data -m fast -w stop
if ($LASTEXITCODE -ne 0) { throw 'Failed to stop the marked QA cluster.' }
Write-Output 'Stopped the marked QA cluster; synthetic data and evidence retained.'
