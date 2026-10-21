<?php

// Worker process for OrderConcurrencyTest (Phase 2).
//
// This is NOT a test and NOT part of the application. The concurrency test starts
// several copies of this script as separate PHP processes to exercise OrderService
// under real concurrency. PHPUnit does not collect it: the suites only cover
// tests/Unit and tests/Feature, and the file name does not end in Test.php.
//
// Safety: the script refuses (exit code 2) unless the process environment, the
// application environment, the resolved database configuration (the same rules as
// the guard in tests/TestCase.php) and the actually connected database all point at
// the dedicated "testing" database. It never creates fixtures, never migrates and
// never deletes anything; only OrderService writes, for the ids it is given. The
// "complete-sale" operation reaches StockService through OrderService::completeSale().

namespace Tests\Support;

use App\Exceptions\OrderException;
use App\Exceptions\StockException;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;
use Throwable;

const ORDER_DIR_PREFIX = 'qart-order-concurrency-';

/**
 * @param  array<string, mixed>  $payload
 */
function orderEncodeJson(array $payload): string
{
    return json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function orderWriteJson(string $path, array $payload): void
{
    $temporary = $path.'.tmp';

    file_put_contents($temporary, orderEncodeJson($payload));
    rename($temporary, $path);
}

/**
 * Print the reason to stderr (and to the result file once the directory is
 * trusted), then exit with code 2. Nothing has been written to the database.
 */
function orderRefuse(string $reason, ?string $resultFile = null): never
{
    fwrite(STDERR, "REFUSED: {$reason}\n");

    if ($resultFile !== null) {
        orderWriteJson($resultFile, ['outcome' => 'refused', 'reason' => $reason]);
    }

    exit(2);
}

function orderTouchFlag(string $dir, string $name): void
{
    file_put_contents($dir.'/'.$name, (string) microtime(true));
}

/**
 * Poll for a flag file; false when the deadline passes first.
 */
function orderWaitFor(string $path, int $seconds): bool
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
function orderSingleOption(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    if ($value === null) {
        return null;
    }

    if (! is_string($value)) {
        orderRefuse("Option --{$name} must be given exactly once, with a value.");
    }

    return $value;
}

// Guards that need neither arguments nor the framework.

if (PHP_SAPI !== 'cli') {
    orderRefuse('This script runs only from the command line.');
}

if (getenv('QART_ORDER_CONCURRENCY_WORKER') !== '1') {
    orderRefuse('QART_ORDER_CONCURRENCY_WORKER=1 is not set. This script is only meant to be started by OrderConcurrencyTest.');
}

if (getenv('APP_ENV') !== 'testing') {
    orderRefuse('The process environment must explicitly set APP_ENV=testing.');
}

if (getenv('DB_DATABASE') !== 'testing') {
    orderRefuse('The process environment must explicitly set DB_DATABASE=testing.');
}

// proc_open() drops environment variables whose value is empty, so a worker started
// by the test has no DB_URL at all. Any non-empty DB_URL is refused here, and the
// resolved connection config is checked again after the framework has booted.
$dbUrl = getenv('DB_URL');

if ($dbUrl !== false && $dbUrl !== '') {
    orderRefuse('DB_URL must be unset or empty.');
}

// Arguments.

$options = getopt('', ['dir:', 'id:', 'op:', 'user:', 'order:', 'variant:', 'quantity:', 'deadline:', 'hold', 'barrier']) ?: [];

$id = orderSingleOption($options, 'id');
$op = orderSingleOption($options, 'op');
$hold = array_key_exists('hold', $options);
$barrier = array_key_exists('barrier', $options);

if ($id === null || ! preg_match('/^[a-z0-9_-]{1,40}$/D', $id)) {
    orderRefuse('--id must be 1 to 40 characters of a-z, 0-9, underscore or hyphen.');
}

if (! in_array($op, ['add-item', 'complete', 'complete-sale'], true)) {
    orderRefuse('--op must be "add-item", "complete" or "complete-sale".');
}

$ids = [];
$required = $op === 'add-item' ? ['user', 'order', 'variant'] : ['user', 'order'];

foreach ($required as $name) {
    $value = orderSingleOption($options, $name);

    if ($value === null || ! preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
        orderRefuse("--{$name} must be a positive integer id.");
    }

    $ids[$name] = (int) $value;
}

$quantity = orderSingleOption($options, 'quantity');

if ($op === 'add-item' && ($quantity === null || $quantity === '')) {
    orderRefuse('--quantity is required for add-item.');
}

$deadlineText = orderSingleOption($options, 'deadline') ?? '30';

if (! preg_match('/^[0-9]{1,2}$/D', $deadlineText) || (int) $deadlineText < 1 || (int) $deadlineText > 60) {
    orderRefuse('--deadline must be a whole number of seconds from 1 to 60.');
}

$deadlineSeconds = (int) $deadlineText;

$dirOption = orderSingleOption($options, 'dir');
$tmpRoot = realpath(sys_get_temp_dir());
$dir = $dirOption === null ? false : realpath($dirOption);

if ($tmpRoot === false
    || $dir === false
    || dirname($dir) !== $tmpRoot
    || ! str_starts_with(basename($dir), ORDER_DIR_PREFIX)
    || ! is_dir($dir)
    || ! is_writable($dir)) {
    orderRefuse('--dir must be an existing, writable directory directly under the system temp directory, named "'.ORDER_DIR_PREFIX.'...".');
}

$resultFile = $dir.'/result-'.$id.'.json';

// Boot the application, then confirm the environment and the database.

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment() !== 'testing') {
    orderRefuse('The application environment is not "testing".', $resultFile);
}

// The same pure rules as the guard of the test process (environment, cached config,
// default connection, driver, database, host, url, unix socket, read/write overrides).
$violations = TestCase::databaseGuardViolations(
    (string) $app->environment(),
    $app->configurationIsCached(),
    config('database.default'),
    config('database.connections.mysql'),
);

if ($violations !== []) {
    orderRefuse('The database guard of tests/TestCase.php failed ['.implode(', ', $violations).'].', $resultFile);
}

// The only query before the guards are confirmed; it reads no table.
$connectedDatabase = DB::selectOne('select database() as db')?->db;

if ($connectedDatabase !== 'testing') {
    orderRefuse('The connected database is not "testing".', $resultFile);
}

// Session-level only: bounds how long this worker can block on a row lock.
DB::statement('set session innodb_lock_wait_timeout = 15');

// Run the operation.

$result = ['id' => $id, 'op' => $op, 'pid' => getmypid()];
$attempts = 0;
$timedOutStage = null;
$held = false;
$startedAt = microtime(true);

try {
    $user = User::query()->findOrFail($ids['user']);
    $order = Order::query()->findOrFail($ids['order']);
    $variant = $op === 'add-item' ? ProductVariant::query()->findOrFail($ids['variant']) : null;

    // Only outermost transactions count (level 1), so a deadlock retry shows up as
    // attempts > 1, while a nested transaction, such as the one inside
    // StockService::recordSale(), does not.
    Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$attempts): void {
        if ($event->connection->transactionLevel() === 1) {
            $attempts++;
        }
    });

    if ($hold) {
        // Fires right after OrderService has locked the order row (the locking read
        // hydrates the model) and before it does anything else. Pause on the first
        // retrieval only, so a later refresh() or a deadlock retry does not pause again.
        Order::retrieved(function () use (&$held, &$timedOutStage, $dir, $id, $deadlineSeconds): void {
            if ($held) {
                return;
            }

            $held = true;
            orderTouchFlag($dir, "holding-{$id}");

            if (! orderWaitFor($dir.'/release-'.$id, $deadlineSeconds)) {
                $timedOutStage = 'release';

                throw new RuntimeException('Timed out waiting for the release signal.');
            }
        });
    }

    orderTouchFlag($dir, "ready-{$id}");

    if ($barrier && ! orderWaitFor($dir.'/go', $deadlineSeconds)) {
        $timedOutStage = 'barrier';

        throw new RuntimeException('Timed out waiting for the go signal.');
    }

    orderTouchFlag($dir, "calling-{$id}");
    $result['calling_at'] = microtime(true);

    $service = $app->make(OrderService::class);

    if ($op === 'add-item') {
        $item = $service->addItem($user, $order, $variant, $quantity);

        $result += [
            'outcome' => 'success',
            'item_id' => $item->getKey(),
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
        ];
    } else {
        $completed = $op === 'complete-sale'
            ? $service->completeSale($user, $order)
            : $service->completeOrder($user, $order);

        $result += [
            'outcome' => 'success',
            'status' => $completed->status->value,
            'completed_at' => $completed->completed_at?->toDateTimeString(),
        ];
    }
} catch (OrderException $e) {
    $result += ['outcome' => 'order_exception', 'reason' => $e->reason];
} catch (StockException $e) {
    $result += [
        'outcome' => 'stock_exception',
        'reason' => $e->reason,
        'product_variant_id' => $e->productVariantId,
    ];
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

orderWriteJson($resultFile, $result);

echo orderEncodeJson($result), "\n";

exit(match ($result['outcome']) {
    'success', 'order_exception', 'stock_exception' => 0,
    'timeout' => 3,
    default => 1,
});
