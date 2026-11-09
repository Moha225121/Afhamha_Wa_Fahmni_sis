param(
    [Parameter(Mandatory = $true)][string] $PgBin,
    [ValidateRange(55439, 55459)][int] $Port = 55439
)
$ErrorActionPreference = 'Stop'
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaBin = (Resolve-Path -LiteralPath $PgBin).Path
foreach ($qaExecutable in @('postgres.exe', 'initdb.exe', 'pg_ctl.exe', 'createdb.exe', 'psql.exe', 'pg_dump.exe', 'pg_restore.exe')) {
    if (!(Test-Path -LiteralPath (Join-Path $qaBin $qaExecutable) -PathType Leaf)) { throw "Missing existing PostgreSQL executable: $qaExecutable" }
}
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) { throw 'Refusing an occupied PostgreSQL QA port.' }
$qaRun = Join-Path $qaRoot ('tmp/qa-postgres/' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $qaRun | Out-Null
$qaData = Join-Path $qaRun 'data'
$qaVersion = (& (Join-Path $qaBin 'postgres.exe') --version).Trim()
& (Join-Path $qaBin 'initdb.exe') -D $qaData -U qa_release_owner --encoding=UTF8 --locale=C --auth-host=trust --auth-local=trust *> (Join-Path $qaRun 'initdb.log')
if ($LASTEXITCODE -ne 0) { throw "initdb failed; see $qaRun/initdb.log" }
@("listen_addresses = '127.0.0.1'", "port = $Port", 'max_connections = 30') | Add-Content -LiteralPath (Join-Path $qaData 'postgresql.conf') -Encoding ASCII
& (Join-Path $qaBin 'pg_ctl.exe') -D $qaData -l (Join-Path $qaRun 'server.log') -w start
if ($LASTEXITCODE -ne 0) { throw 'The new isolated PostgreSQL cluster failed to start.' }
try {
    foreach ($qaDatabase in @('afhamha_testing', 'nasbia_qa_upgrade', 'nasbia_qa_restore')) {
        & (Join-Path $qaBin 'createdb.exe') --host=127.0.0.1 --port=$Port --username=qa_release_owner --no-password $qaDatabase
        if ($LASTEXITCODE -ne 0) { throw "Failed to create new QA database $qaDatabase" }
    }
    $qaMarker = Join-Path $qaRun 'cluster.json'
    [ordered]@{ purpose='nasbia-postgres-qa'; root=$qaRoot; run=$qaRun; data=$qaData; bin=$qaBin; host='127.0.0.1'; port=$Port; user='qa_release_owner'; databases=@('afhamha_testing','nasbia_qa_upgrade','nasbia_qa_restore'); version=$qaVersion; created_utc=[DateTime]::UtcNow.ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $qaMarker -Encoding UTF8
    Write-Output "QA_POSTGRES_MARKER=$qaMarker"
} catch {
    & (Join-Path $qaBin 'pg_ctl.exe') -D $qaData -m fast -w stop
    throw
}
