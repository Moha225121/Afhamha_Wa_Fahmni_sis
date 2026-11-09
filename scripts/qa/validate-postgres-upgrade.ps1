param(
    [Parameter(Mandatory = $true)][string] $Marker,
    [string] $BaselineManifest = ''
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'postgres-common.ps1')
$qaCluster = Read-QaPostgresMarker $Marker
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaBaselineRef = '1a5cfe27c72e38acd05c837682a3f29b0d7b3402'
$qaEvidence = Join-Path $qaCluster.run ('upgrade-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $qaEvidence | Out-Null
$qaBaseline = Join-Path $qaEvidence 'baseline'
$qaArchive = Join-Path $qaEvidence 'baseline-migrations.zip'
$qaReleaseMigrations = @(
    'database/migrations/2026_11_08_000001_add_fcm_outbox_payload.php',
    'database/migrations/2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php'
)
if (!$BaselineManifest -and !(Test-Path -LiteralPath (Join-Path $qaRoot '.git'))) {
    $BaselineManifest = Join-Path $qaRoot 'docs/deployment/BASELINE_MIGRATIONS.json'
}
$qaState = Set-QaPostgresEnvironment $qaCluster $qaRoot 'nasbia_qa_upgrade'
try {
    Push-Location $qaRoot
    Assert-QaPostgresIdentity $qaCluster 'nasbia_qa_upgrade'
    if ($BaselineManifest) {
        $qaManifestPath = (Resolve-Path -LiteralPath $BaselineManifest).Path
        $qaManifest = Get-Content -LiteralPath $qaManifestPath -Raw | ConvertFrom-Json
        if ($qaManifest.schema_version -ne 1 -or $qaManifest.baseline_ref -ne $qaBaselineRef -or $qaManifest.migration_count -ne 42 -or
            @($qaManifest.files).Count -ne 44 -or
            (Compare-Object @($qaManifest.excluded_release_migrations) $qaReleaseMigrations)) {
            throw 'Invalid baseline manifest identity, migration count, or release exclusions.'
        }
        $qaSeenPaths = @{}
        foreach ($qaEntry in $qaManifest.files) {
            $qaRelative = [string] $qaEntry.path
            if (($qaRelative -notmatch '^database/migrations/[A-Za-z0-9_]+\.php$' -and $qaRelative -notin @('config/school_accounts.php','config/smart_tutor.php')) -or
                $qaRelative -in $qaReleaseMigrations -or $qaSeenPaths.ContainsKey($qaRelative) -or $qaEntry.sha256 -notmatch '^[0-9a-f]{64}$') {
                throw 'Unsafe, duplicate, or invalid baseline manifest entry.'
            }
            $qaSeenPaths[$qaRelative] = $true
            $qaSource = Join-Path $qaRoot $qaRelative
            if (!(Test-Path -LiteralPath $qaSource -PathType Leaf) -or (Get-FileHash -Algorithm SHA256 -LiteralPath $qaSource).Hash -ne $qaEntry.sha256) {
                throw "Baseline source SHA256 mismatch: $qaRelative. Refusing to manufacture baseline history."
            }
        }
        $qaActualMigrations = @(Get-ChildItem -LiteralPath (Join-Path $qaRoot 'database/migrations') -File | ForEach-Object { 'database/migrations/' + $_.Name })
        $qaExpectedMigrations = @($qaManifest.files | Where-Object { $_.path.StartsWith('database/migrations/') } | ForEach-Object { $_.path })
        if ($qaExpectedMigrations.Count -ne 42 -or !$qaSeenPaths.ContainsKey('config/school_accounts.php') -or !$qaSeenPaths.ContainsKey('config/smart_tutor.php') -or
            (Compare-Object $qaActualMigrations @($qaExpectedMigrations + $qaReleaseMigrations))) {
            throw 'Source migration inventory differs from the verified baseline plus the two release migrations.'
        }
        # These verified temporary copies stay under the marked QA run, never in the release package.
        foreach ($qaEntry in $qaManifest.files) {
            $qaDestination = Join-Path $qaBaseline $qaEntry.path
            New-Item -ItemType Directory -Path (Split-Path $qaDestination -Parent) -Force | Out-Null
            Copy-Item -LiteralPath (Join-Path $qaRoot $qaEntry.path) -Destination $qaDestination
        }
        $qaOrigin = [ordered]@{ mode='verified-source-manifest'; baseline_ref=$qaBaselineRef; manifest_sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath $qaManifestPath).Hash.ToLowerInvariant(); verified_files=44; verified_migrations=42 }
    } else {
        git archive --format=zip --output=$qaArchive $qaBaselineRef database/migrations config/school_accounts.php config/smart_tutor.php
        if ($LASTEXITCODE -ne 0) { throw 'The exact baseline Git object is unavailable; provide the verified -BaselineManifest explicitly.' }
        Expand-Archive -LiteralPath $qaArchive -DestinationPath $qaBaseline
        $qaOrigin = [ordered]@{ mode='git-archive'; baseline_ref=$qaBaselineRef; archive_sha256=(Get-FileHash -Algorithm SHA256 -LiteralPath $qaArchive).Hash.ToLowerInvariant() }
    }
    $qaOrigin | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $qaEvidence 'baseline-origin.json') -Encoding UTF8
    $qaPhp = (& php -r 'echo PHP_BINARY;').Trim()
    & $qaPhp -d memory_limit=512M scripts/qa/postgres-upgrade.php $Marker baseline $qaBaseline $qaEvidence
    if ($LASTEXITCODE -ne 0) { throw 'Baseline validation failed; evidence retained without resetting the database.' }
    $qaBeforeDump = Join-Path $qaEvidence 'before-upgrade.dump'
    & (Join-Path $qaCluster.bin 'pg_dump.exe') -Fc --no-owner --no-privileges --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_upgrade --file=$qaBeforeDump
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic baseline backup failed.' }
    & $qaPhp -d memory_limit=512M scripts/qa/postgres-upgrade.php $Marker upgrade $qaBaseline $qaEvidence
    if ($LASTEXITCODE -ne 0) { throw 'Upgrade validation failed; preserve the fixture for investigation.' }
    $qaAfterDump = Join-Path $qaEvidence 'after-upgrade.dump'
    & (Join-Path $qaCluster.bin 'pg_dump.exe') -Fc --no-owner --no-privileges --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_upgrade --file=$qaAfterDump
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic upgraded backup failed.' }
    Assert-QaPostgresIdentity $qaCluster 'nasbia_qa_restore'
    $qaTableCount = & (Join-Path $qaCluster.bin 'psql.exe') -X --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_restore -Atc "SELECT count(*) FROM information_schema.tables WHERE table_schema='public';"
    if ($LASTEXITCODE -ne 0 -or $qaTableCount.Trim() -ne '0') { throw 'Refusing to restore over an existing database.' }
    & (Join-Path $qaCluster.bin 'pg_restore.exe') --exit-on-error --no-owner --no-privileges --host=127.0.0.1 --port=$($qaCluster.port) --username=qa_release_owner --no-password --dbname=nasbia_qa_restore $qaAfterDump
    if ($LASTEXITCODE -ne 0) { throw 'Synthetic restore failed.' }
    $env:DB_DATABASE = 'nasbia_qa_restore'
    & $qaPhp -d memory_limit=512M scripts/qa/postgres-upgrade.php $Marker restore $qaBaseline $qaEvidence
    if ($LASTEXITCODE -ne 0) { throw 'Restored data validation failed.' }
    Write-Output "QA_POSTGRES_UPGRADE_EVIDENCE=$qaEvidence"
} finally {
    Pop-Location
    Restore-QaPostgresEnvironment $qaState
}
