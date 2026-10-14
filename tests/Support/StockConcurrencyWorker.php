<?php

// Worker process for StockConcurrencyTest (Task #007).
//
// This is NOT a test and NOT part of the application. The concurrency test starts
// several copies of this script as separate PHP processes to exercise StockService
// under real concurrency. PHPUnit does not collect it: the suites only cover
// tests/Unit and tests/Feature, and the file name does not end in Test.php.
//
// Safety: the script refuses (exit code 2) unless the process environment, the
// application environment, the configured database and the actually connected
// database are all "testing". It never creates fixtures, never migrates and never
// deletes anything; only StockService writes, for the ids it is given.

namespace Tests\Support;

use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;

const DIR_PREFIX = 'qart-stock-concurrency-';

/**
 * @param  array<string, mixed>  $payload
 */
function encodeJson(array $payload): string
{
    return json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function writeJson(string $path, array $payload): void
{
    $temporary = $path.'.tmp';

    file_put_contents($temporary, encodeJson($payload));
    rename($temporary, $path);
}

/**
 * Print the reason to stderr (and to the result file once the directory is
 * trusted), then exit with code 2. Nothing has been written to the database.
 */
function refuse(string $reason, ?string $resultFile = null): never
{
    fwrite(STDERR, "REFUSED: {$reason}\n");

    if ($resultFile !== null) {
        writeJson($resultFile, ['outcome' => 'refused', 'reason' => $reason]);
    }

    exit(2);
}

function touchFlag(string $dir, string $name): void
{
    file_put_contents($dir.'/'.$name, (string) microtime(true));
}

/**
 * Poll for a flag file; false when the deadline passes first.
 */
function waitFor(string $path, int $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (true) {
        clearstatcache(true, $path);

        if (file_exists($path)) {
            return true;
        }

        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep(2000);
    }
}

/**
 * @param  array<string, mixed>  $options
 */
function singleOption(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    if ($value === null) {
        return null;
    }

    if (! is_string($value)) {
        refuse("Option --{$name} must be given exactly once, with a value.");
    }

    return $value;
}

// Guards that need neither arguments nor the framework.

if (PHP_SAPI !== 'cli') {
    refuse('This script runs only from the command line.');
}

if (getenv('QART_CONCURRENCY_WORKER') !== '1') {
    refuse('QART_CONCURRENCY_WORKER=1 is not set. This script is only meant to be started by StockConcurrencyTest.');
}

if (getenv('APP_ENV') !== 'testing') {
    refuse('The process environment must explicitly set APP_ENV=testing.');
}

if (getenv('DB_DATABASE') !== 'testing') {
    refuse('The process environment must explicitly set DB_DATABASE=testing.');
}

// proc_open() drops environment variables whose value is empty, so a worker started
// by the test has no DB_URL at all. Any non-empty DB_URL is refused here, and the
// resolved connection config is checked again after the framework has booted.
$dbUrl = getenv('DB_URL');

if ($dbUrl !== false && $dbUrl !== '') {
    refuse('DB_URL must be unset or empty.');
}

// Arguments.

$options = getopt('', ['dir:', 'id:', 'op:', 'user:', 'outlet:', 'variant:', 'quantity:', 'note:', 'deadline:', 'business:', 'hold', 'barrier']) ?: [];

$id = singleOption($options, 'id');
$op = singleOption($options, 'op');
$quantity = singleOption($options, 'quantity');
$note = singleOption($options, 'note');
$hold = array_key_exists('hold', $options);
$barrier = array_key_exists('barrier', $options);

if ($id === null || ! preg_match('/^[a-z0-9_-]{1,40}$/D', $id)) {
    refuse('--id must be 1 to 40 characters of a-z, 0-9, underscore or hyphen.');
}

if (! in_array($op, ['adjust', 'opening', 'delete-business'], true)) {
    refuse('--op must be "adjust", "opening" or "delete-business".');
}

if ($quantity === null || $quantity === '') {
    refuse('--quantity is required.');
}

$ids = [];

foreach (['user', 'outlet', 'variant'] as $name) {
    $value = singleOption($options, $name);

    if ($value === null || ! preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
        refuse("--{$name} must be a positive integer id.");
    }

    $ids[$name] = (int) $value;
}

$deadlineText = singleOption($options, 'deadline') ?? '30';

if (! preg_match('/^[0-9]{1,2}$/D', $deadlineText) || (int) $deadlineText < 1 || (int) $deadlineText > 60) {
    refuse('--deadline must be a whole number of seconds from 1 to 60.');
}

$deadlineSeconds = (int) $deadlineText;

$businessId = null;

if ($op === 'delete-business') {
    $businessValue = singleOption($options, 'business');

    if ($businessValue === null || ! preg_match('/^[1-9][0-9]{0,17}$/D', $businessValue)) {
        refuse('--business must be a positive integer id for the delete-business operation.');
    }

    $businessId = (int) $businessValue;
}

$dirOption = singleOption($options, 'dir');
$tmpRoot = realpath(sys_get_temp_dir());
$dir = $dirOption === null ? false : realpath($dirOption);

if ($tmpRoot === false
    || $dir === false
    || dirname($dir) !== $tmpRoot
    || ! str_starts_with(basename($dir), DIR_PREFIX)
    || ! is_dir($dir)
    || ! is_writable($dir)) {
    refuse('--dir must be an existing, writable directory directly under the system temp directory, named "'.DIR_PREFIX.'...".');
}

$resultFile = $dir.'/result-'.$id.'.json';

// Boot the application, then confirm the environment and the database.

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment() !== 'testing') {
    refuse('The application environment is not "testing".', $resultFile);
}

$connectionName = config('database.default');
$connectionConfig = config("database.connections.{$connectionName}", []);

if (($connectionConfig['driver'] ?? null) !== 'mysql'
    || ($connectionConfig['database'] ?? null) !== 'testing'
    || ($connectionConfig['url'] ?? '') !== '') {
    refuse('The default connection must be mysql, with database "testing" and no connection url.', $resultFile);
}

// The only query before the guards are confirmed; it reads no table.
$connectedDatabase = DB::selectOne('select database() as db')?->db;

if ($connectedDatabase !== 'testing') {
    refuse('The connected database is not "testing".', $resultFile);
}

// Session-level only: bounds how long this worker can block on a row lock.
DB::statement('set session innodb_lock_wait_timeout = 10');

// Run the operation.

$result = ['id' => $id, 'op' => $op, 'pid' => getmypid()];
$attempts = 0;
$timedOutStage = null;
$held = false;
$startedAt = microtime(true);

try {
    $user = User::query()->findOrFail($ids['user']);
    $outlet = Outlet::query()->findOrFail($ids['outlet']);
    $variant = ProductVariant::query()->findOrFail($ids['variant']);

    // Every begun transaction counts, so a deadlock retry shows up as attempts > 1.
    Event::listen(TransactionBeginning::class, function () use (&$attempts): void {
        $attempts++;
    });

    if ($hold && $op !== 'delete-business') {
        // Fires right after StockService has locked the stock-level row (the locking
        // read hydrates the model) and before it calculates the balance. Pause on the
        // first retrieval only, so a deadlock retry does not pause again.
        StockLevel::retrieved(function () use (&$held, &$timedOutStage, $dir, $id, $deadlineSeconds): void {
            if ($held) {
                return;
            }

            $held = true;
            touchFlag($dir, "holding-{$id}");

            if (! waitFor($dir.'/release-'.$id, $deadlineSeconds)) {
                $timedOutStage = 'release';

                throw new RuntimeException('Timed out waiting for the release signal.');
            }
        });
    }

    if ($hold && $op === 'delete-business') {
        // Pause once, right after Business::delete() has locked the outlet rows (the
        // "... from `outlets` ... for update" read), before it checks the stock history.
        DB::listen(function (QueryExecuted $query) use (&$held, &$timedOutStage, $dir, $id, $deadlineSeconds): void {
            if ($held || ! str_contains($query->sql, 'from `outlets`') || ! str_contains($query->sql, 'for update')) {
                return;
            }

            $held = true;
            touchFlag($dir, "holding-{$id}");

            if (! waitFor($dir.'/release-'.$id, $deadlineSeconds)) {
                $timedOutStage = 'release';

                throw new RuntimeException('Timed out waiting for the release signal.');
            }
        });
    }

    touchFlag($dir, "ready-{$id}");

    if ($barrier && ! waitFor($dir.'/go', $deadlineSeconds)) {
        $timedOutStage = 'barrier';

        throw new RuntimeException('Timed out waiting for the go signal.');
    }

    touchFlag($dir, "calling-{$id}");
    $result['calling_at'] = microtime(true);

    if ($op === 'delete-business') {
        // --user, --outlet, --variant and --quantity are required for every operation
        // and ignored here; the business to delete is given by --business.
        $deleted = Business::query()->findOrFail($businessId)->delete();

        $result += ['outcome' => 'success', 'deleted' => $deleted === true];
    } else {
        $service = $app->make(StockService::class);

        $movement = $op === 'opening'
            ? $service->recordOpening($user, $outlet, $variant, $quantity, $note)
            : $service->adjust($user, $outlet, $variant, $quantity, $note);

        $result += [
            'outcome' => 'success',
            'movement_id' => $movement->getKey(),
            'type' => $movement->type->value,
            'quantity_delta' => $movement->quantity_delta,
            'quantity_after' => $movement->quantity_after,
        ];
    }
} catch (StockException $e) {
    $result += ['outcome' => 'stock_exception', 'reason' => $e->reason];
} catch (Throwable $e) {
    if ($timedOutStage !== null) {
        $result += ['outcome' => 'timeout', 'stage' => $timedOutStage];
    } else {
        $result += [
            'outcome' => 'error',
            'class' => $e::class,
            'message' => mb_substr($e->getMessage(), 0, 500),
        ];

        if ($e instanceof QueryException) {
            $result['sql_state'] = $e->errorInfo[0] ?? null;
            $result['driver_code'] = $e->errorInfo[1] ?? null;
        }
    }
}

$finishedAt = microtime(true);

$result += [
    'attempts' => $attempts,
    'finished_at' => $finishedAt,
    'duration_ms' => (int) round(($finishedAt - ($result['calling_at'] ?? $startedAt)) * 1000),
];

writeJson($resultFile, $result);

echo encodeJson($result), "\n";

exit(match ($result['outcome']) {
    'success', 'stock_exception' => 0,
    'timeout' => 3,
    default => 1,
});
