<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        $isolatedDatabase = ($connection === 'pgsql' && $database === 'afhamha_testing')
            || ($connection === 'sqlite' && $database === ':memory:');

        if (! $app->environment('testing') || ! $isolatedDatabase) {
            throw new RuntimeException(
                'Refusing to run application tests outside PostgreSQL afhamha_testing or in-memory SQLite.',
            );
        }

        return $app;
    }
}
