<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    private const GUARD_CONNECTION = 'mysql';

    private const GUARD_DATABASE = 'testing';

    private const GUARD_HOST = 'mysql';

    /**
     * Boot the application, then refuse to continue unless the resolved
     * database configuration points at the dedicated test database.
     *
     * refreshApplication() runs before setUpTraits() starts RefreshDatabase,
     * so a failed guard stops the test before any migration is executed.
     *
     * Limitation: parent::createApplication() has already booted every service
     * provider when the guard runs, so it cannot stop database access that
     * happens during provider boot.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $violations = self::databaseGuardViolations(
            (string) $app->environment(),
            $app->configurationIsCached(),
            $app['config']->get('database.default'),
            $app['config']->get('database.connections.'.self::GUARD_CONNECTION),
        );

        if ($violations === []) {
            $violations = self::liveDatabaseGuardViolations($app);
        }

        if ($violations !== []) {
            throw new RuntimeException(
                'Test database safety guard failed ['.implode(', ', $violations).']. '
                .'Aborted before any migration. Check phpunit.xml and the resolved database configuration.'
            );
        }

        return $app;
    }

    /**
     * Pure rule check on already-resolved values (no framework, no database).
     * Returns rule codes only, never configuration values.
     *
     * @return list<string>
     */
    public static function databaseGuardViolations(
        string $environment,
        bool $configurationCached,
        mixed $defaultConnection,
        mixed $connection,
    ): array {
        $violations = [];

        if ($environment !== 'testing') {
            $violations[] = 'app-env-not-testing';
        }

        if ($configurationCached) {
            $violations[] = 'config-cached';
        }

        if ($defaultConnection !== self::GUARD_CONNECTION) {
            $violations[] = 'default-connection-not-mysql';
        }

        if (! is_array($connection)) {
            $violations[] = 'mysql-connection-config-missing';

            return $violations;
        }

        if (($connection['driver'] ?? null) !== 'mysql') {
            $violations[] = 'driver-not-mysql';
        }

        if (($connection['database'] ?? null) !== self::GUARD_DATABASE) {
            $violations[] = 'database-not-testing';
        }

        if (($connection['host'] ?? null) !== self::GUARD_HOST) {
            $violations[] = 'host-not-mysql';
        }

        foreach (['url', 'unix_socket'] as $key) {
            $value = $connection[$key] ?? null;

            if ($value !== null && $value !== '' && $value !== []) {
                $violations[] = $key.'-not-empty';
            }
        }

        foreach (['read', 'write'] as $key) {
            if (array_key_exists($key, $connection)) {
                $violations[] = $key.'-override-present';
            }
        }

        return $violations;
    }

    /**
     * Read-only confirmation of the database actually connected to.
     *
     * @return list<string>
     */
    private static function liveDatabaseGuardViolations($app): array
    {
        try {
            $row = $app['db']->connection(self::GUARD_CONNECTION)->selectOne('select database() as db');
        } catch (Throwable $e) {
            return ['live-check-failed ('.$e::class.')'];
        }

        return ($row->db ?? null) === self::GUARD_DATABASE ? [] : ['live-database-not-testing'];
    }
}
