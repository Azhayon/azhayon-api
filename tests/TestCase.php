<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes the database, so refuse to run against anything
     * that is not clearly a test database.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $default = config('database.default');
        $database = config("database.connections.{$default}.database");

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new \RuntimeException(
                'Refusing to run tests against a non-test database: '.var_export($database, true)
            );
        }
    }
}