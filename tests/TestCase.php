<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** The ONLY database the suite may ever touch. */
    private const TEST_DATABASE = 'ponos_testing';

    /**
     * Refuse to run against anything but the test database.
     *
     * phpunit.xml sets DB_DATABASE=ponos_testing, but a cached config
     * (bootstrap/cache/config.php) freezes whatever DB_DATABASE was baked in at
     * cache-build time — env() is not re-evaluated once config is cached — so
     * the override is silently ignored and RefreshDatabase's migrate:fresh drops
     * and recreates every table in the DEV database instead.
     *
     * That has now happened four times on this project. Clearing the cache
     * beforehand is not a reliable defence: anything running `php artisan
     * optimize` puts it straight back, mid-session.
     *
     * This runs after the application is booted but BEFORE setUpTraits(), which
     * is what triggers RefreshDatabase — so it aborts while the data is still
     * there.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(
                "Refusing to run tests against database '{$database}'. Expected '".self::TEST_DATABASE."'.\n".
                "This almost always means bootstrap/cache/config.php exists and is overriding phpunit.xml.\n".
                'Run `php artisan config:clear` and try again.'
            );
        }
    }
}
