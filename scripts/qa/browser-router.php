<?php

$qaPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$qaPublic = realpath(dirname(__DIR__, 2).'/public');
$qaFile = realpath($qaPublic.'/'.ltrim(urldecode($qaPath), '/'));
if ($qaPath !== '/' && $qaFile && str_starts_with($qaFile, $qaPublic.DIRECTORY_SEPARATOR) && is_file($qaFile)) {
    return false;
}
$app = require __DIR__.'/browser-bootstrap.php';
$app->handleRequest(Illuminate\Http\Request::capture());
