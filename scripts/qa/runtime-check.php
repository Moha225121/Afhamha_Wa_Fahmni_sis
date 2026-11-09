<?php

// Compile application artifacts against a fresh synthetic fixture, never .env.
ob_start();
require __DIR__.'/create-browser-fixture.php';
ob_end_clean();

// route:cache creates a fresh Application. This isolated configuration cache
// ensures that its bootstrap also skips the real environment file.
$configPath = $app->getCachedConfigPath();
file_put_contents($configPath, '<?php return '.var_export($app['config']->all(), true).';');
$cachedConfig = require $configPath;
if ($cachedConfig['database']['default'] !== 'sqlite'
    || $cachedConfig['database']['connections']['sqlite']['database'] !== $qaRun.'/qa.sqlite') {
    throw new RuntimeException('Refusing runtime checks outside the synthetic database.');
}

$results = ['configuration serialization' => ['exit' => 0]];
foreach (['route:cache', 'view:cache', 'schedule:list'] as $command) {
    $exit = Illuminate\Support\Facades\Artisan::call($command);
    $results[$command] = ['exit' => $exit, 'output' => trim(Illuminate\Support\Facades\Artisan::output())];
    if ($exit !== 0) {
        throw new RuntimeException($command.' failed in the isolated runtime.');
    }
}
$results['route_count'] = count($app['router']->getRoutes());
$results['testedAt'] = gmdate(DATE_ATOM);
$results['environment'] = 'Fresh isolated SQLite fixture, synthetic configuration cache, no production environment file';
file_put_contents($qaRoot.'/docs/qa/evidence/runtime-check.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
echo json_encode(['result' => 'PASS', 'routes' => $results['route_count'], 'checks' => 4]).PHP_EOL;
