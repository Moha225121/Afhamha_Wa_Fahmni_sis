param(
    [string] $BaseUrl = 'http://127.0.0.1:5500',
    [string] $Email = 'parent@example.test',
    [string] $Password = 'password123'
)

$ErrorActionPreference = 'Stop'

Set-Location $PSScriptRoot

$base = $BaseUrl.TrimEnd('/')
$dashboardUrl = "$base/parent/dashboard"
$headersPath = Join-Path $PSScriptRoot 'storage\app\lighthouse-headers.json'
$reportPath = Join-Path $PSScriptRoot 'storage\app\parent-pwa-lighthouse.json'
$tempPath = Join-Path $PSScriptRoot 'storage\app\lighthouse-tmp'

New-Item -ItemType Directory -Force (Split-Path $headersPath) | Out-Null
New-Item -ItemType Directory -Force $tempPath | Out-Null

try {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -Uri "$base/login" -WebSession $session -UseBasicParsing
    $token = [regex]::Match($login.Content, 'name="_token" value="([^"]+)"').Groups[1].Value

    if (-not $token) {
        throw 'CSRF token was not found on the login page.'
    }

    Invoke-WebRequest `
        -Uri "$base/login" `
        -Method Post `
        -WebSession $session `
        -UseBasicParsing `
        -Body @{ _token = $token; email = $Email; password = $Password } `
        -MaximumRedirection 5 | Out-Null

    $dashboard = Invoke-WebRequest -Uri $dashboardUrl -WebSession $session -UseBasicParsing -TimeoutSec 10

    if ($dashboard.StatusCode -ne 200) {
        throw "Parent dashboard returned HTTP $($dashboard.StatusCode)."
    }

    $cookieHeader = (($session.Cookies.GetCookies($base) | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join '; ')
    @{ Cookie = $cookieHeader } | ConvertTo-Json -Compress | Set-Content -Path $headersPath -Encoding Ascii

    $env:TEMP = $tempPath
    $env:TMP = $tempPath

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $lighthouseOutput = & cmd.exe /c npx --yes --cache .npm-cache lighthouse@11 $dashboardUrl --quiet --only-categories=pwa --output=json --output-path=storage/app/parent-pwa-lighthouse.json --extra-headers=storage/app/lighthouse-headers.json --chrome-flags="--headless=new --no-sandbox --disable-gpu --user-data-dir=storage/app/lighthouse-tmp/chrome-profile" 2>&1
        $lighthouseExit = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if (-not (Test-Path $reportPath)) {
        $lighthouseOutput | ForEach-Object { Write-Host $_ }
        exit $lighthouseExit
    }

    $report = Get-Content $reportPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $score = $report.categories.pwa.score

    Write-Host "PWA score: $score"
    Write-Host "Final URL: $($report.finalUrl)"

    if ($lighthouseExit -ne 0) {
        Write-Host 'Lighthouse wrote the report, but Chrome returned a local temp cleanup warning.'
    }

    foreach ($id in @('installable-manifest', 'splash-screen', 'maskable-icon')) {
        $audit = $report.audits.$id

        if ($audit) {
            Write-Host "${id}: $($audit.score) - $($audit.title)"
        }
    }

    if ($score -eq 1) {
        exit 0
    }

    if ($lighthouseExit -ne 0) {
        exit $lighthouseExit
    }

    exit 1
} finally {
    if (Test-Path $headersPath) {
        Remove-Item -LiteralPath $headersPath -Force
    }
}
