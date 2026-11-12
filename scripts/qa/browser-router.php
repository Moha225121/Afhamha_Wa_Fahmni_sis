<?php

$qaPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
// Emulate the hosting public-storage link for this isolated fixture only.
// Never serve the developer's real public/storage link or real upload files.
if (str_starts_with($qaPath, '/storage/')) {
    $qaBase = realpath(dirname(__DIR__, 2).'/tmp/qa-browser');
    $qaRun = realpath((string) getenv('QA_BROWSER_RUN'));
    $qaStorage = $qaRun ? realpath($qaRun.'/storage/app/public') : false;
    $qaRelative = urldecode(substr($qaPath, strlen('/storage/')));
    $qaFile = $qaStorage && ! str_contains($qaRelative, "\0") ? realpath($qaStorage.'/'.$qaRelative) : false;
    if (! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)
        || ! $qaBase || ! $qaRun || ! str_starts_with($qaRun, $qaBase.DIRECTORY_SEPARATOR)
        || ! $qaStorage || ! str_starts_with($qaStorage, $qaRun.DIRECTORY_SEPARATOR)
        || ! $qaFile || ! str_starts_with($qaFile, $qaStorage.DIRECTORY_SEPARATOR) || ! is_file($qaFile)) {
        http_response_code(404);
        return true;
    }
    header('Content-Type: '.mime_content_type($qaFile));
    header('Content-Length: '.filesize($qaFile));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') readfile($qaFile);
    return true;
}
$qaPublic = realpath(dirname(__DIR__, 2).'/public');
$qaFile = realpath($qaPublic.'/'.ltrim(urldecode($qaPath), '/'));
if ($qaPath !== '/' && $qaFile && str_starts_with($qaFile, $qaPublic.DIRECTORY_SEPARATOR) && is_file($qaFile)) {
    return false;
}
$app = require __DIR__.'/browser-bootstrap.php';
$app->handleRequest(Illuminate\Http\Request::capture());
