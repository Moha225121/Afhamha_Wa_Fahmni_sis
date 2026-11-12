param([Parameter(Mandatory = $true)][string] $Marker)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'postgres-common.ps1')
$qaCluster = Read-QaPostgresMarker $Marker
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaBaselineRef = '1756cc4bf1019561c6caded736819cb3d62a81b7'
$qaEvidence = Join-Path $qaCluster.run ('round-two-upgrade-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $qaEvidence | Out-Null
$qaBaseline = Join-Path $qaEvidence 'baseline'
$qaArchive = Join-Path $qaEvidence 'baseline-migrations.zip'
$qaMigrations = @('2026_11_11_000001_add_fcm_event_cancellation.php', '2026_11_11_000002_create_lesson_save_requests.php')
$qaState = Set-QaPostgresEnvironment $qaCluster $qaRoot 'nasbia_qa_upgrade'
try {
    Push-Location $qaRoot
    Assert-QaPostgresIdentity $qaCluster 'nasbia_qa_upgrade'
    Assert-QaPostgresIdentity $qaCluster 'nasbia_qa_restore'
    foreach ($qaDatabase in @('nasbia_qa_upgrade', 'nasbia_qa_restore')) {
        $qaCount = & (Join-Path $qaCluster.bin 'psql.exe') -X --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=$qaDatabase -Atc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';"
        if ($LASTEXITCODE -ne 0 -or $qaCount.Trim() -ne '0') { throw 'This probe requires two unused QA databases and never resets existing data.' }
    }
    git archive --format=zip --output=$qaArchive $qaBaselineRef database/migrations config/school_accounts.php config/smart_tutor.php
    if ($LASTEXITCODE -ne 0) { throw 'Exact Round 2 baseline commit unavailable.' }
    Expand-Archive -LiteralPath $qaArchive -DestinationPath $qaBaseline
    $qaBaselineFiles = @(Get-ChildItem -LiteralPath (Join-Path $qaBaseline 'database/migrations') -File)
    if ($qaBaselineFiles.Count -ne 44) { throw 'Unexpected Round 2 baseline migration inventory.' }
    foreach ($qaFile in $qaBaselineFiles) {
        if ((Get-FileHash -Algorithm SHA256 -LiteralPath $qaFile.FullName).Hash -ne (Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $qaRoot ('database/migrations/' + $qaFile.Name))).Hash) {
            throw ('Previously released migration changed: ' + $qaFile.Name)
        }
    }
    $qaReleaseHashes = @($qaMigrations | ForEach-Object { [ordered]@{path=('database/migrations/' + $_); sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $qaRoot ('database/migrations/' + $_))).Hash.ToLowerInvariant()} })
    $qaPhp = (& php -r 'echo PHP_BINARY;').Trim()
    foreach ($qaPhase in @('baseline', 'upgrade')) {
        & $qaPhp -d memory_limit=512M scripts/qa/round-two-postgres-upgrade.php $Marker $qaPhase $qaBaseline $qaEvidence
        if ($LASTEXITCODE -ne 0) { throw ('Round 2 ' + $qaPhase + ' failed; retained the isolated database and evidence.') }
        $qaDump = Join-Path $qaEvidence ($qaPhase + '.dump')
        & (Join-Path $qaCluster.bin 'pg_dump.exe') -Fc --no-owner --no-privileges --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_upgrade --file=$qaDump
        if ($LASTEXITCODE -ne 0) { throw 'Synthetic PostgreSQL backup failed.' }
    }
    Assert-QaPostgresIdentity $qaCluster 'nasbia_qa_restore'
    & (Join-Path $qaCluster.bin 'pg_restore.exe') --exit-on-error --no-owner --no-privileges --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_restore (Join-Path $qaEvidence 'upgrade.dump')
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic PostgreSQL restore failed.' }
    $env:DB_DATABASE = 'nasbia_qa_restore'
    & $qaPhp -d memory_limit=512M scripts/qa/round-two-postgres-upgrade.php $Marker restore $qaBaseline $qaEvidence
    if ($LASTEXITCODE -ne 0) { throw 'Restored row verification failed.' }
    $qaSummary = [ordered]@{
        result='PASS'; baseline_ref=$qaBaselineRef; baseline_migrations=44; migrations=$qaReleaseHashes
        baseline_archive_sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath $qaArchive).Hash.ToLowerInvariant()
        backup_sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $qaEvidence 'baseline.dump')).Hash.ToLowerInvariant()
        upgraded_backup_sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath (Join-Path $qaEvidence 'upgrade.dump')).Hash.ToLowerInvariant()
        phases=@('baseline','upgrade','restore' | ForEach-Object { Get-Content -LiteralPath (Join-Path $qaEvidence ($_ + '-result.json')) -Raw | ConvertFrom-Json })
        restored_rows=(Get-Content -LiteralPath (Join-Path $qaEvidence 'upgraded-snapshot.json') -Raw | ConvertFrom-Json)
        limitations='Synthetic PostgreSQL on Windows only; no production access, providers, or production-size lock timing.'
    }
    $qaDestination = Join-Path $qaRoot 'docs/qa/round-2/evidence/postgres-round-two-upgrade.json'
    New-Item -ItemType Directory -Path (Split-Path $qaDestination -Parent) -Force | Out-Null
    $qaSummary | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $qaDestination -Encoding utf8
    Write-Output "QA_ROUND_TWO_UPGRADE_EVIDENCE=$qaEvidence"
    Write-Output "QA_ROUND_TWO_UPGRADE_SUMMARY=$qaDestination"
} finally {
    Pop-Location
    Restore-QaPostgresEnvironment $qaState
}
