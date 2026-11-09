<?php

// Synthetic loopback-only upgrade fixture. This is never a production router.
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    return;
}
$qaRoot = dirname(__DIR__, 2);
$qaBase = realpath($qaRoot.'/tmp/qa-browser');
$qaRun = realpath((string) getenv('QA_BROWSER_RUN'));
if (! $qaBase || ! $qaRun || ! str_starts_with($qaRun.DIRECTORY_SEPARATOR, $qaBase.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('PWA upgrade router requires an isolated QA fixture.');
}
$qaStateFile = $qaRun.'/pwa-upgrade-state.json';
$qaState = is_file($qaStateFile) ? json_decode(file_get_contents($qaStateFile), true, flags: JSON_THROW_ON_ERROR) : [];
$qaPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$qaPortal = $qaState['portal'] ?? '';
if (($qaState['phase'] ?? '') === 'baseline' && in_array($qaPortal, ['parent', 'student'], true)) {
    if ($qaPath === '/'.$qaPortal.'-sw.js') {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-store');
        readfile(__DIR__.'/fixtures/'.$qaPortal.'-sw-baseline.js');
        return;
    }
    if (in_array($qaPath, ['/icons/parent-icon.svg', '/icons/parent-icon-192.png', '/icons/parent-icon-512.png', '/icons/parent-maskable-512.png'], true)) {
        // Baseline referenced assets missing from this checkout. Supply only
        // approved synthetic icon bytes so an already-installable old worker
        // can be represented; this is NOT proof of a historic device artifact.
        require_once $qaRoot.'/vendor/autoload.php';
        $app = require __DIR__.'/browser-bootstrap.php';
        header('Content-Type: image/png');
        header('Cache-Control: no-store');
        echo App\Support\SchoolBranding::iconPng(str_contains($qaPath, '512') ? 512 : 192);
        return;
    }
}
return require __DIR__.'/browser-router.php';
