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

        if (! $app->environment('testing') || $connection !== 'pgsql' || $database !== 'afhamha_testing') {
            throw new RuntimeException(
                'Refusing to run application tests outside PostgreSQL database afhamha_testing.',
            );
        }

        return $app;
    }
}
