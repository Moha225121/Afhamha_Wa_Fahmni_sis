<?php

// Used exclusively by the loopback QA fixture and router. Never load .env.
require_once dirname(__DIR__, 2).'/vendor/autoload.php';

$qaRoot = dirname(__DIR__, 2);
$qaBase = realpath($qaRoot.'/tmp/qa-browser');
$qaRun = realpath((string) getenv('QA_BROWSER_RUN'));
if (! $qaBase || ! $qaRun || ! str_starts_with($qaRun.DIRECTORY_SEPARATOR, $qaBase.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('QA_BROWSER_RUN must be a new run below tmp/qa-browser.');
}

$qaBaseUrl = getenv('QA_BASE_URL') ?: 'http://127.0.0.1:8765';
if (! in_array($qaBaseUrl, ['http://127.0.0.1:8765', 'http://127.0.0.1:8766'], true)) {
    throw new RuntimeException('QA_BASE_URL must be an approved loopback QA origin.');
}

$qaPostgres = null;
if ($qaDescriptorInput = getenv('QA_BROWSER_PG_DESCRIPTOR')) {
    $qaPgBase = realpath($qaRoot.'/tmp/qa-postgres');
    $qaDescriptorPath = realpath($qaDescriptorInput);
    if (! $qaPgBase || ! $qaDescriptorPath || ! str_starts_with($qaDescriptorPath, $qaPgBase.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('PostgreSQL browser descriptor must be inside this workspace QA cluster directory.');
    }
    $qaPostgres = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($qaDescriptorPath)), true, flags: JSON_THROW_ON_ERROR);
    $qaMarkerPath = realpath($qaPostgres['cluster_marker'] ?? '');
    if (! $qaMarkerPath || dirname($qaMarkerPath) !== dirname($qaDescriptorPath)) {
        throw new RuntimeException('PostgreSQL browser descriptor has no matching cluster marker.');
    }
    $qaCluster = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($qaMarkerPath)), true, flags: JSON_THROW_ON_ERROR);
    if (($qaPostgres['purpose'] ?? '') !== 'nasbia-postgres-browser-qa'
        || ($qaPostgres['host'] ?? '') !== '127.0.0.1' || ($qaPostgres['port'] ?? 0) !== 55439
        || ($qaPostgres['database'] ?? '') !== 'nasbia_qa_browser'
        || ($qaPostgres['username'] ?? '') !== 'qa_release_owner'
        || ($qaCluster['purpose'] ?? '') !== 'nasbia-postgres-qa'
        || ($qaCluster['host'] ?? '') !== '127.0.0.1' || ($qaCluster['port'] ?? 0) !== 55439
        || realpath($qaCluster['root'] ?? '') !== realpath($qaRoot)
        || ! realpath($qaPostgres['data_directory'] ?? '')
        || realpath($qaCluster['data'] ?? '') !== realpath($qaPostgres['data_directory'])) {
        throw new RuntimeException('PostgreSQL browser endpoint does not match the explicitly created isolated cluster.');
    }
}

$qaEnvironment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
    'APP_DEBUG' => 'false',
    'APP_URL' => $qaBaseUrl,
    // Laravel treats drive-prefixed cache overrides as relative on Windows.
    'APP_CONFIG_CACHE' => 'tmp/qa-browser/'.basename($qaRun).'/unused-config.php',
    'APP_ROUTES_CACHE' => 'tmp/qa-browser/'.basename($qaRun).'/unused-routes.php',
    'LARAVEL_STORAGE_PATH' => $qaRun.'/storage',
    'VIEW_COMPILED_PATH' => $qaRun.'/storage/framework/views',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $qaRun.'/qa.sqlite',
    'DB_URL' => '',
    'DB_PASSWORD' => '',
    'MAIL_MAILER' => 'array',
    'CACHE_STORE' => 'file',
    'SESSION_DRIVER' => 'file',
    'SESSION_SECURE_COOKIE' => 'false',
    'QUEUE_CONNECTION' => 'sync',
    'DB_QUEUE_CONNECTION' => 'sqlite',
    'FILESYSTEM_DISK' => 'local',
    'LOG_CHANNEL' => 'single',
    'GEMINI_API_KEY' => '',
    'FIREBASE_PROJECT_ID' => '',
    'GOOGLE_APPLICATION_CREDENTIALS' => '',
    'VAPID_PUBLIC_KEY' => '',
    'VAPID_PRIVATE_KEY' => '',
];
if ($qaPostgres !== null) {
    $qaEnvironment = array_replace($qaEnvironment, [
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '55439',
        'DB_DATABASE' => 'nasbia_qa_browser', 'DB_USERNAME' => 'qa_release_owner',
        'DB_QUEUE_CONNECTION' => 'pgsql',
    ]);
}
foreach ($qaEnvironment as $qaName => $qaValue) {
    putenv($qaName.'='.$qaValue);
    $_ENV[$qaName] = $_SERVER[$qaName] = $qaValue;
}
$app = require $qaRoot.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.qa-browser-disabled');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
if ($qaPostgres === null && (config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== $qaRun.'/qa.sqlite'
    || config('database.connections.sqlite.url'))) {
    throw new RuntimeException('QA browser connection isolation failed.');
}
if ($qaPostgres !== null) {
    if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.url')
        || config('database.connections.pgsql.host') !== '127.0.0.1'
        || (int) config('database.connections.pgsql.port') !== 55439
        || config('database.connections.pgsql.database') !== 'nasbia_qa_browser'
        || config('database.connections.pgsql.username') !== 'qa_release_owner') {
        throw new RuntimeException('PostgreSQL browser config isolation failed before connection.');
    }
    $qaIdentity = Illuminate\Support\Facades\DB::selectOne("SELECT current_database() AS db, current_user AS username, current_setting('data_directory') AS data_directory");
    if ($qaIdentity->db !== 'nasbia_qa_browser' || $qaIdentity->username !== 'qa_release_owner'
        || realpath($qaIdentity->data_directory) !== realpath($qaPostgres['data_directory'])) {
        throw new RuntimeException('PostgreSQL browser server identity does not match its isolated marker.');
    }
}

return $app;
