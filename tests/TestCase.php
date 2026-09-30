<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against a real database.
     *
     * If config is cached (e.g. on staging/production), phpunit.xml's env values are ignored and
     * RefreshDatabase would wipe the real database. Stop before any trait touches the DB.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $env = $app->environment();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($env !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                "Tests refused to run: environment is [{$env}] using [{$connection}:{$database}], "
                ."not the in-memory test database. Config is probably cached. "
                ."Run tests locally, or: php artisan config:clear && php artisan test"
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Views use @vite; tests don't need built assets.
        $this->withoutVite();
    }
}
