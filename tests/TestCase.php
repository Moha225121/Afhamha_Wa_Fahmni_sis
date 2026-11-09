<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Bootstrap without reading the developer's real .env, including when
        // Windows removes empty process variables instead of setting them.
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__.'/Fixtures/no-environment');
        $app->loadEnvironmentFrom('.env.disabled-for-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Refusing real cached configuration. Use scripts/qa/run-isolated.ps1.');
        }
        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }
        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }
        $app->make(Kernel::class)->bootstrap();
        $connection = $app['config']->get('database.default');
        // Resolve DB_URL as Laravel does, without opening a connection. A safe
        // database name in config must not disguise a remote URL override.
        $databaseConfig = $app['db']->connection($connection)->getConfig();
        $database = $databaseConfig['database'] ?? null;
        $host = $databaseConfig['host'] ?? null;

        $isolatedDatabase = ($connection === 'pgsql' && $database === 'afhamha_testing'
                && in_array($host, ['127.0.0.1', '::1', 'localhost'], true)
                && getenv('QA_ALLOW_LOCAL_POSTGRES') === '1')
            || ($connection === 'sqlite' && $database === ':memory:');

        if (! $app->environment('testing') || ! $isolatedDatabase) {
            throw new RuntimeException(
                'Tests require in-memory SQLite, or explicitly approved loopback PostgreSQL afhamha_testing (QA_ALLOW_LOCAL_POSTGRES=1).',
            );
        }

        Http::preventStrayRequests();
        $app['config']->set([
            'queue.connections.database.connection' => $connection,
            'services.firebase.credentials' => null,
            'services.firebase.project_id' => null,
            'services.web_push.public_key' => null,
            'services.web_push.private_key' => null,
            'mail.default' => 'array',
        ]);

        return $app;
    }
}
