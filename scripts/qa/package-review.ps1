param([switch] $ManifestOnly)

$ErrorActionPreference = 'Stop'
$qaRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$qaUtf8 = New-Object System.Text.UTF8Encoding($false)
$qaCodePaths = @('.env.example', 'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests', 'scripts')
Push-Location $qaRoot
try {
    $qaBase = (& git rev-parse HEAD).Trim()
    $qaChanged = @(& git -c core.quotepath=false diff --name-only --diff-filter=ACMR HEAD -- @qaCodePaths)
    $qaNew = @(& git -c core.quotepath=false ls-files --others --exclude-standard -- @qaCodePaths)
    $qaFiles = @(@($qaChanged + $qaNew) | Where-Object { $_ } | Sort-Object -Unique)
    if (-not $qaFiles.Count) { throw 'No review changes found.' }
    $qaLines = @('# Nasbia root changes; apply only to this exact base.', "# Base HEAD: $qaBase")
    foreach ($qaRelative in $qaFiles) {
        $qaPath = [IO.Path]::GetFullPath((Join-Path $qaRoot $qaRelative))
        if (-not $qaPath.StartsWith($qaRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
            throw 'Refusing a file outside the repository.'
        }
        $qaLines += ((Get-FileHash -LiteralPath $qaPath -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $qaRelative.Replace('\', '/'))
    }
    $qaManifest = Join-Path $qaRoot 'docs/deployment/CHANGESET_SHA256.txt'
    [IO.File]::WriteAllText($qaManifest, ($qaLines -join "`n") + "`n", $qaUtf8)
    $qaDigest = (Get-FileHash -LiteralPath $qaManifest -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($ManifestOnly) {
        @{ base = $qaBase; files = $qaFiles.Count; code_manifest_sha256 = $qaDigest } | ConvertTo-Json -Compress
        exit 0
    }

    # This is a delta for review, not a complete runnable/deployable release.
    # Explicit paths exclude original reports, .env, uploads and existing output.
    $qaDocs = @(Get-ChildItem -LiteralPath (Join-Path $qaRoot 'docs/qa'), (Join-Path $qaRoot 'docs/deployment') -File -Recurse |
        ForEach-Object { $_.FullName.Substring($qaRoot.Length + 1).Replace('\', '/') })
    $qaEntries = @(@($qaFiles + $qaDocs) | Sort-Object -Unique)
    $qaDestination = Join-Path $qaRoot ('output/qa-review-nasbia-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 6))
    New-Item -ItemType Directory -Path $qaDestination | Out-Null
    $qaArchivePath = Join-Path $qaDestination 'nasbia-qa-review.zip'
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $qaArchive = [IO.Compression.ZipFile]::Open($qaArchivePath, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($qaRelative in $qaEntries) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($qaArchive, (Join-Path $qaRoot $qaRelative), $qaRelative.Replace('\', '/'), [IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally { $qaArchive.Dispose() }
    $qaArchiveHash = (Get-FileHash -LiteralPath $qaArchivePath -Algorithm SHA256).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText($qaArchivePath + '.sha256', $qaArchiveHash + '  nasbia-qa-review.zip' + "`n", $qaUtf8)
    @{ base = $qaBase; code_files = $qaFiles.Count; entries = $qaEntries.Count; code_manifest_sha256 = $qaDigest; archive = $qaArchivePath; archive_sha256 = $qaArchiveHash } | ConvertTo-Json -Compress
} finally { Pop-Location }
