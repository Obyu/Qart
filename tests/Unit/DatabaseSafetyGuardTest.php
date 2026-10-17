<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\TestCase as ApplicationTestCase;

/**
 * Permanent tests for the rules of the test database safety guard.
 *
 * They call the real Tests\TestCase::databaseGuardViolations() with plain values.
 * This class deliberately extends PHPUnit's own TestCase: no application is booted,
 * no database connection is opened and no database trait is used.
 */
class DatabaseSafetyGuardTest extends TestCase
{
    #[DataProvider('safeConfigurations')]
    public function test_a_safe_configuration_has_no_violations(array $connection): void
    {
        $this->assertSame([], ApplicationTestCase::databaseGuardViolations('testing', false, 'mysql', $connection));
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_an_unsafe_configuration_reports_exactly_the_violated_rules(
        string $environment,
        bool $configurationCached,
        mixed $defaultConnection,
        array $connection,
        array $expected,
    ): void {
        $this->assertSame(
            $expected,
            ApplicationTestCase::databaseGuardViolations($environment, $configurationCached, $defaultConnection, $connection),
        );
    }

    #[DataProvider('nonArrayConnections')]
    public function test_a_connection_config_that_is_not_an_array_is_reported_as_missing(mixed $connection): void
    {
        $this->assertSame(
            ['mysql-connection-config-missing'],
            ApplicationTestCase::databaseGuardViolations('testing', false, 'mysql', $connection),
        );
    }

    public function test_a_missing_connection_config_still_reports_the_environment_level_rules(): void
    {
        $this->assertSame(
            ['app-env-not-testing', 'config-cached', 'default-connection-not-mysql', 'mysql-connection-config-missing'],
            ApplicationTestCase::databaseGuardViolations('local', true, 'sqlite', null),
        );
    }

    public function test_violations_are_rule_codes_and_never_contain_configuration_values(): void
    {
        $connection = self::safeConnection([
            'host' => 'sentinel-host.invalid',
            'database' => 'sentinel-database',
            'url' => 'mysql://sentinel-url.invalid/sentinel-path',
            'unix_socket' => '/tmp/sentinel.sock',
        ]);

        $violations = ApplicationTestCase::databaseGuardViolations('sentinel-env', true, 'sentinel-connection', $connection);

        $this->assertNotSame([], $violations);
        $this->assertTrue(array_is_list($violations));

        foreach ($violations as $violation) {
            $this->assertMatchesRegularExpression('/^[a-z_]+(?:-[a-z_]+)*$/', $violation);
            $this->assertStringNotContainsString('sentinel', $violation);
        }
    }

    public function test_the_guard_is_declared_on_the_application_test_case_itself(): void
    {
        $method = new ReflectionMethod(ApplicationTestCase::class, 'createApplication');

        $this->assertSame(ApplicationTestCase::class, $method->getDeclaringClass()->getName());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function safeConfigurations(): array
    {
        return [
            'standard configuration' => [self::safeConnection()],
            'url and unix socket are null' => [self::safeConnection(['url' => null, 'unix_socket' => null])],
            'url and unix socket are absent' => [self::connectionWithout('url', 'unix_socket')],
        ];
    }

    /**
     * @return array<string, array{string, bool, mixed, array<string, mixed>, list<string>}>
     */
    public static function unsafeConfigurations(): array
    {
        $safe = self::safeConnection();

        return [
            // Application environment.
            'environment is local' => ['local', false, 'mysql', $safe, ['app-env-not-testing']],
            'environment is production' => ['production', false, 'mysql', $safe, ['app-env-not-testing']],
            'environment is empty' => ['', false, 'mysql', $safe, ['app-env-not-testing']],
            'environment differs only by case' => ['Testing', false, 'mysql', $safe, ['app-env-not-testing']],

            // A cached configuration would bake in values that phpunit.xml cannot override.
            'configuration is cached' => ['testing', true, 'mysql', $safe, ['config-cached']],

            // Default connection.
            'default connection is sqlite' => ['testing', false, 'sqlite', $safe, ['default-connection-not-mysql']],
            'default connection is null' => ['testing', false, null, $safe, ['default-connection-not-mysql']],
            'default connection differs only by case' => ['testing', false, 'MySQL', $safe, ['default-connection-not-mysql']],
            'default connection is mariadb' => ['testing', false, 'mariadb', $safe, ['default-connection-not-mysql']],

            // Driver.
            'driver is sqlite' => ['testing', false, 'mysql', self::safeConnection(['driver' => 'sqlite']), ['driver-not-mysql']],
            'driver is mariadb' => ['testing', false, 'mysql', self::safeConnection(['driver' => 'mariadb']), ['driver-not-mysql']],
            'driver is missing' => ['testing', false, 'mysql', self::connectionWithout('driver'), ['driver-not-mysql']],

            // Database name.
            'database is the development database' => ['testing', false, 'mysql', self::safeConnection(['database' => 'laravel']), ['database-not-testing']],
            'database has a parallel style suffix' => ['testing', false, 'mysql', self::safeConnection(['database' => 'testing_1']), ['database-not-testing']],
            'database differs only by case' => ['testing', false, 'mysql', self::safeConnection(['database' => 'Testing']), ['database-not-testing']],
            'database has a trailing space' => ['testing', false, 'mysql', self::safeConnection(['database' => 'testing ']), ['database-not-testing']],
            'database is empty' => ['testing', false, 'mysql', self::safeConnection(['database' => '']), ['database-not-testing']],
            'database is null' => ['testing', false, 'mysql', self::safeConnection(['database' => null]), ['database-not-testing']],
            'database is missing' => ['testing', false, 'mysql', self::connectionWithout('database'), ['database-not-testing']],

            // Host.
            'host is an ip address' => ['testing', false, 'mysql', self::safeConnection(['host' => '127.0.0.1']), ['host-not-mysql']],
            'host is localhost' => ['testing', false, 'mysql', self::safeConnection(['host' => 'localhost']), ['host-not-mysql']],
            'host differs only by case' => ['testing', false, 'mysql', self::safeConnection(['host' => 'MYSQL']), ['host-not-mysql']],
            'host is empty' => ['testing', false, 'mysql', self::safeConnection(['host' => '']), ['host-not-mysql']],
            'host is missing' => ['testing', false, 'mysql', self::connectionWithout('host'), ['host-not-mysql']],

            // Connection url and unix socket would bypass the host restriction.
            'url is set' => ['testing', false, 'mysql', self::safeConnection(['url' => 'mysql://example.invalid/laravel']), ['url-not-empty']],
            'unix socket is set' => ['testing', false, 'mysql', self::safeConnection(['unix_socket' => '/tmp/example.sock']), ['unix_socket-not-empty']],

            // Read and write overrides would bypass the host restriction.
            'read override with hosts' => ['testing', false, 'mysql', self::safeConnection(['read' => ['host' => ['replica.example.invalid']]]), ['read-override-present']],
            'write override with hosts' => ['testing', false, 'mysql', self::safeConnection(['write' => ['host' => ['primary.example.invalid']]]), ['write-override-present']],
            'read override with an empty array' => ['testing', false, 'mysql', self::safeConnection(['read' => []]), ['read-override-present']],
            'read and write overrides together' => ['testing', false, 'mysql', self::safeConnection(['read' => [], 'write' => []]), ['read-override-present', 'write-override-present']],

            // Several violations at once, in the order the guard reports them.
            'database and host are both wrong' => ['testing', false, 'mysql', self::safeConnection(['database' => 'laravel', 'host' => '127.0.0.1']), ['database-not-testing', 'host-not-mysql']],
            'every rule is violated at once' => [
                'local',
                true,
                'sqlite',
                [
                    'driver' => 'sqlite',
                    'url' => 'mysql://example.invalid/laravel',
                    'host' => '127.0.0.1',
                    'database' => 'laravel',
                    'unix_socket' => '/tmp/example.sock',
                    'read' => [],
                    'write' => [],
                ],
                [
                    'app-env-not-testing',
                    'config-cached',
                    'default-connection-not-mysql',
                    'driver-not-mysql',
                    'database-not-testing',
                    'host-not-mysql',
                    'url-not-empty',
                    'unix_socket-not-empty',
                    'read-override-present',
                    'write-override-present',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonArrayConnections(): array
    {
        return [
            'null' => [null],
            'a string' => ['mysql'],
            'false' => [false],
            'an empty string' => [''],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function safeConnection(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'url' => '',
            'host' => 'mysql',
            'database' => 'testing',
            'unix_socket' => '',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function connectionWithout(string ...$keys): array
    {
        $connection = self::safeConnection();

        foreach ($keys as $key) {
            unset($connection[$key]);
        }

        return $connection;
    }
}
