function Read-QaPostgresMarker([string] $Marker) {
    $qaPath = (Resolve-Path -LiteralPath $Marker).Path
    $qaCluster = Get-Content -LiteralPath $qaPath -Raw | ConvertFrom-Json
    $qaExpectedBase = [IO.Path]::GetFullPath((Join-Path $qaCluster.root 'tmp/qa-postgres')) + [IO.Path]::DirectorySeparatorChar
    $qaResolvedRun = (Resolve-Path -LiteralPath $qaCluster.run).Path
    $qaResolvedData = (Resolve-Path -LiteralPath $qaCluster.data).Path
    if ($qaCluster.purpose -ne 'nasbia-postgres-qa' -or $qaCluster.host -ne '127.0.0.1' -or $qaCluster.user -ne 'qa_release_owner' -or $qaCluster.port -lt 55439 -or $qaCluster.port -gt 55459 -or
        !$qaResolvedRun.StartsWith($qaExpectedBase, [StringComparison]::OrdinalIgnoreCase) -or
        $qaResolvedData -ne (Join-Path $qaResolvedRun 'data') -or
        $qaPath -ne (Join-Path $qaResolvedRun 'cluster.json')) { throw 'Invalid isolated PostgreSQL marker or workspace path.' }
    $qaPidPath = Join-Path $qaResolvedData 'postmaster.pid'
    $qaPid = [int](Get-Content -LiteralPath $qaPidPath -TotalCount 1)
    $qaProcess = Get-CimInstance Win32_Process -Filter "ProcessId=$qaPid"
    if (!$qaProcess -or $qaProcess.Name -ne 'postgres.exe' -or !$qaProcess.CommandLine.Replace('/','\').Contains($qaResolvedData)) { throw 'The marker does not identify the running isolated PostgreSQL cluster.' }
    return $qaCluster
}

function Set-QaPostgresEnvironment($Cluster, [string] $SourceRoot, [string] $Database = 'afhamha_testing') {
    if ($Database -notin @('afhamha_testing','nasbia_qa_upgrade','nasbia_qa_restore')) { throw 'Refusing a non-QA database name.' }
    $qaOutput = Join-Path $SourceRoot ('tmp/qa-pg-runs/' + [Guid]::NewGuid().ToString('N'))
    $qaStorage = Join-Path $qaOutput 'storage'
    foreach ($qaRelative in @('framework/views','framework/sessions','framework/cache/data','logs','app/private','app/public')) {
        New-Item -ItemType Directory -Path (Join-Path $qaStorage $qaRelative) -Force | Out-Null
    }
    $qaVariables = @{
        APP_ENV='testing'; APP_KEY='base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI='; APP_URL='http://127.0.0.1'; APP_DEBUG='false'
        APP_CONFIG_CACHE=('tmp/qa-pg-runs/' + (Split-Path $qaOutput -Leaf) + '/unused-config.php'); APP_ROUTES_CACHE=('tmp/qa-pg-runs/' + (Split-Path $qaOutput -Leaf) + '/unused-routes.php')
        LARAVEL_STORAGE_PATH=$qaStorage; VIEW_COMPILED_PATH=(Join-Path $qaStorage 'framework/views')
        DB_CONNECTION='pgsql'; DB_HOST='127.0.0.1'; DB_PORT=[string]$Cluster.port; DB_DATABASE=$Database; DB_USERNAME='qa_release_owner'; DB_PASSWORD=''; DB_URL=''; DB_SSLMODE='disable'
        QA_ALLOW_LOCAL_POSTGRES='1'; MAIL_MAILER='array'; CACHE_STORE='array'; SESSION_DRIVER='array'; QUEUE_CONNECTION='sync'; DB_QUEUE_CONNECTION='pgsql'; FILESYSTEM_DISK='local'; LOG_CHANNEL='single'
        GEMINI_API_KEY=''; FIREBASE_PROJECT_ID=''; GOOGLE_APPLICATION_CREDENTIALS=''; VAPID_PUBLIC_KEY=''; VAPID_PRIVATE_KEY=''
        PGHOST='127.0.0.1'; PGPORT=[string]$Cluster.port; PGUSER='qa_release_owner'; PGDATABASE=$Database; PGPASSWORD=''; PGSERVICE=''; PGSERVICEFILE=(Join-Path $qaOutput 'no-service.conf'); PGPASSFILE=(Join-Path $qaOutput 'no-password.conf'); PGSSLMODE='disable'
    }
    $qaPrevious = @{}
    foreach ($qaKey in $qaVariables.Keys) {
        $qaPrevious[$qaKey] = [Environment]::GetEnvironmentVariable($qaKey, 'Process')
        [Environment]::SetEnvironmentVariable($qaKey, $qaVariables[$qaKey], 'Process')
    }
    return @{ output=$qaOutput; previous=$qaPrevious }
}

function Restore-QaPostgresEnvironment($State) {
    foreach ($qaKey in $State.previous.Keys) { [Environment]::SetEnvironmentVariable($qaKey, $State.previous[$qaKey], 'Process') }
}

function Assert-QaPostgresIdentity($Cluster, [string] $Database) {
    $qaIdentity = & (Join-Path $Cluster.bin 'psql.exe') -X --no-password --host=127.0.0.1 --port=$($Cluster.port) --username=qa_release_owner --dbname=$Database -Atc "SELECT current_setting('data_directory') || '|' || current_database() || '|' || current_user;"
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL identity check failed.' }
    $qaParts = $qaIdentity.Trim().Split('|')
    if ($qaParts.Length -ne 3 -or $qaParts[0].Replace('/','\') -ne $Cluster.data.Replace('/','\') -or $qaParts[1] -ne $Database -or $qaParts[2] -ne 'qa_release_owner') { throw 'Connected database is not the marked isolated cluster.' }
}
