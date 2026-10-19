<?php

namespace Tests\Feature;

use App\Exceptions\OrderException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real-concurrency tests for OrderService (Phase 2).
 *
 * Several PHP processes (tests/Support/OrderConcurrencyWorker.php) call the service
 * at the same time against the committed "testing" database. This class does not use
 * RefreshDatabase: it creates its own fixtures, records their exact ids and removes
 * only those rows afterwards. It never migrates, truncates or resets anything.
 *
 * A pass means no violation was observed in these runs. It is not a proof that no
 * interleaving can break the service. Deadlock retries and infrastructure errors fail
 * the test with the full worker output; they are never hidden.
 */
#[Group('concurrency')]
class OrderConcurrencyTest extends TestCase
{
    private const TABLES = [
        'users',
        'businesses',
        'outlets',
        'categories',
        'products',
        'product_variants',
        'orders',
        'order_items',
    ];

    private const DIR_PREFIX = 'qart-order-concurrency-';

    private const READY_SECONDS = 30;

    private const RESULT_SECONDS = 40;

    private User $owner;

    private Business $business;

    private Outlet $outlet;

    private Category $category;

    private OrderService $service;

    /** @var array<string, list<int>> */
    private array $tracked = [
        'users' => [],
        'businesses' => [],
        'outlets' => [],
        'categories' => [],
        'products' => [],
        'product_variants' => [],
        'orders' => [],
    ];

    /** @var array<string, int> */
    private array $countsBefore = [];

    private ?string $dir = null;

    /** @var array<string, array{process: resource, exitCode: int|null}> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->guardEnvironment();
            $this->snapshotCounts();
            $this->createTemporaryDirectory();
            $this->createFixtures();
        } catch (Throwable $e) {
            $this->reportProblems($this->cleanUp());

            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $problems = $this->cleanUp();

        parent::tearDown();

        if ($problems !== []) {
            $this->reportProblems($problems);
            $this->fail('Cleanup of the concurrency fixtures reported problems: '.implode(' | ', $problems));
        }
    }

    // Scenario 1: parallel additions of one variant to one order

    public function test_concurrent_additions_of_one_variant_to_one_order_lose_no_quantity(): void
    {
        $order = $this->newOrder();
        $variant = $this->newVariant();

        $quantities = [
            'add-1' => '1',
            'add-2' => '2',
            'add-3' => '3',
            'add-4' => '4',
            'add-5' => '5',
            'add-6' => '6',
        ];

        $results = $this->runBarrierRound('add-item', $order->id, $variant->id, $quantities);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);
        $this->assertCount(6, $this->successfulWorkers($results), 'Every addition fits in the limit, so every one must succeed. '.json_encode($results));

        $items = $this->itemsOf($order->id);

        $this->assertCount(1, $items, 'One variant in one order must stay one row.');
        $this->assertSame(21, (int) $items[0]->quantity);
        $this->assertSame((int) $variant->id, (int) $items[0]->product_variant_id);
        $this->assertSame('open', $this->orderRow($order->id)->status);
        $this->assertSnapshotMatchesCatalog($items[0], $variant->id);

        foreach ($results as $id => $result) {
            $this->assertSame((int) $items[0]->id, (int) $result['item_id'], "Worker {$id} reported a different item row.");
        }

        $this->assertQuantityChain($results, array_values($quantities));
    }

    // Scenario 2: parallel additions that together exceed the maximum

    public function test_concurrent_additions_beyond_the_maximum_keep_the_limit(): void
    {
        $order = $this->newOrder();
        $variant = $this->newVariant();

        $quantities = [
            'big-1' => '400000',
            'big-2' => '400000',
            'big-3' => '400000',
        ];

        $results = $this->runBarrierRound('add-item', $order->id, $variant->id, $quantities);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);

        // 400,000 + 400,000 fits in 1,000,000; a third 400,000 does not.
        $this->assertCount(2, $this->successfulWorkers($results), 'Exactly two additions fit. '.json_encode($results));
        $this->assertCount(1, $this->rejectedWorkers($results), 'The third addition must be rejected.');
        $this->assertRejectionReasons($results, OrderException::REASON_QUANTITY_OVERFLOW);

        $returned = array_map(fn ($id) => (int) $results[$id]['quantity'], $this->successfulWorkers($results));
        sort($returned);

        $this->assertSame([400_000, 800_000], $returned);

        $items = $this->itemsOf($order->id);

        $this->assertCount(1, $items);
        $this->assertSame(800_000, (int) $items[0]->quantity);
        $this->assertLessThanOrEqual(OrderService::MAX_QUANTITY, (int) $items[0]->quantity);
    }

    // Scenario 3: parallel completions of one order

    public function test_concurrent_completions_complete_the_order_exactly_once(): void
    {
        $order = $this->newOrder();
        $variant = $this->newVariant();
        $this->service->addItem($this->owner, $order, $variant, 2);

        $workers = [
            'done-1' => null,
            'done-2' => null,
            'done-3' => null,
            'done-4' => null,
        ];

        $results = $this->runBarrierRound('complete', $order->id, null, $workers);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);

        $successes = $this->successfulWorkers($results);

        $this->assertCount(1, $successes, 'Exactly one completion must succeed. '.json_encode($results));
        $this->assertCount(3, $this->rejectedWorkers($results), 'The other three must be rejected.');
        $this->assertRejectionReasons($results, OrderException::REASON_ORDER_NOT_OPEN);
        $this->assertSame('completed', $results[$successes[0]]['status']);

        $row = $this->orderRow($order->id);

        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);

        $items = $this->itemsOf($order->id);

        $this->assertCount(1, $items);
        $this->assertSame(2, (int) $items[0]->quantity);
    }

    // Scenario 4: a completion holds the order lock, an addition waits for it

    public function test_a_completion_holding_the_order_lock_makes_a_waiting_addition_fail_cleanly(): void
    {
        $order = $this->newOrder();
        $existing = $this->newVariant();
        $added = $this->newVariant();
        $this->service->addItem($this->owner, $order, $existing, 2);

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'complete', 'variant' => null, 'quantity' => null],
            ['op' => 'add-item', 'variant' => $added->id, 'quantity' => '4'],
        );

        $results = $run['results'];
        $problems = $run['problems'];

        if ($this->classify($results['holder']) !== 'success' || ($results['holder']['status'] ?? null) !== 'completed') {
            $problems[] = 'The completion did not succeed: '.json_encode($results['holder']);
        }

        if ($this->classify($results['waiter']) !== 'order_rejection'
            || ($results['waiter']['reason'] ?? null) !== OrderException::REASON_ORDER_NOT_OPEN) {
            $problems[] = 'The waiting addition was not refused with order_not_open: '.json_encode($results['waiter']);
        }

        foreach ($results as $id => $result) {
            if (isset($result['attempts']) && (int) $result['attempts'] !== 1) {
                $problems[] = "Deadlock retry observed: worker {$id} began its transaction {$result['attempts']} times.";
            }
        }

        $row = $this->orderRow($order->id);

        if ($row->status !== 'completed' || $row->completed_at === null) {
            $problems[] = 'The order must be completed with a completed_at.';
        }

        $items = $this->itemsOf($order->id);

        if (count($items) !== 1
            || (int) $items[0]->product_variant_id !== (int) $existing->id
            || (int) $items[0]->quantity !== 2) {
            $problems[] = 'The order must still hold exactly the original item (quantity 2): '.json_encode($items);
        }

        $this->assertSame(
            [],
            $problems,
            'Completion holding the order lock first, then a waiting addition. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );
    }

    // Scenario 5: an addition holds the order lock on an empty order, a completion waits for it

    public function test_an_addition_holding_the_order_lock_on_an_empty_order_is_seen_by_a_waiting_completion(): void
    {
        $order = $this->newOrder();
        $variant = $this->newVariant();

        $this->assertSame([], $this->itemsOf($order->id), 'Control: the order must be empty before the scenario starts.');

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'add-item', 'variant' => $variant->id, 'quantity' => '3'],
            ['op' => 'complete', 'variant' => null, 'quantity' => null],
        );

        $results = $run['results'];
        $problems = $run['problems'];

        if ($this->classify($results['holder']) !== 'success' || (int) ($results['holder']['quantity'] ?? 0) !== 3) {
            $problems[] = 'The addition did not succeed with quantity 3: '.json_encode($results['holder']);
        }

        // The completion must read the order only after the lock, so it sees the new item.
        // Seeing an empty order here would mean the item check ran outside the order lock.
        if ($this->classify($results['waiter']) !== 'success' || ($results['waiter']['status'] ?? null) !== 'completed') {
            $problems[] = 'The waiting completion did not succeed (an empty_order rejection would mean it did not see the item): '.json_encode($results['waiter']);
        }

        foreach ($results as $id => $result) {
            if (isset($result['attempts']) && (int) $result['attempts'] !== 1) {
                $problems[] = "Deadlock retry observed: worker {$id} began its transaction {$result['attempts']} times.";
            }
        }

        $row = $this->orderRow($order->id);

        if ($row->status !== 'completed' || $row->completed_at === null) {
            $problems[] = 'The order must be completed with a completed_at.';
        }

        $items = $this->itemsOf($order->id);

        if (count($items) !== 1
            || (int) $items[0]->product_variant_id !== (int) $variant->id
            || (int) $items[0]->quantity !== 3) {
            $problems[] = 'The order must hold exactly the new item (quantity 3): '.json_encode($items);
        }

        $this->assertSame(
            [],
            $problems,
            'Addition holding the order lock first on an empty order, then a waiting completion. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );
    }

    // Scenario 6: the classification used by the assertions above

    public function test_worker_failures_are_never_mistaken_for_order_rejections(): void
    {
        $cases = [
            'success' => ['outcome' => 'success'],
            'order_rejection' => ['outcome' => 'order_exception', 'reason' => 'order_not_open'],
            'deadlock' => ['outcome' => 'error', 'driver_code' => 1213, 'message' => 'x'],
            'lock_timeout' => ['outcome' => 'error', 'driver_code' => 1205, 'message' => 'x'],
            'unexpected_error' => ['outcome' => 'error', 'class' => 'RuntimeException', 'message' => 'boom'],
            'worker_timeout' => ['outcome' => 'timeout'],
            'refused' => ['outcome' => 'refused'],
        ];

        foreach ($cases as $expected => $result) {
            $this->assertSame($expected, $this->classify($result), "Case {$expected}");
        }

        $this->assertSame('deadlock', $this->classify(['outcome' => 'error', 'message' => 'SQLSTATE[40001]: Deadlock found when trying to get lock']));
        $this->assertSame('lock_timeout', $this->classify(['outcome' => 'error', 'message' => 'Lock wait timeout exceeded; try restarting transaction']));
        $this->assertSame('unknown', $this->classify(['outcome' => 'something-else']));
        $this->assertSame('unknown', $this->classify(['outcome' => 'stock_exception']));
    }

    // Environment guards, fixtures and cleanup

    private function guardEnvironment(): void
    {
        if (app()->environment() !== 'testing') {
            $this->fail('Refusing to run: the application environment is "'.app()->environment().'", not "testing".');
        }

        $configured = DB::connection()->getDatabaseName();

        if ($configured !== 'testing') {
            $this->fail('Refusing to run: the configured database is "'.$configured.'", not "testing".');
        }

        $connected = DB::selectOne('select database() as db')?->db;

        if ($connected !== 'testing') {
            $this->fail('Refusing to run: the connected database is "'.(string) $connected.'", not "testing".');
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->fail("Refusing to run: table {$table} is missing from the testing database. This test never migrates; run the full test suite once so the existing RefreshDatabase tests prepare it.");
            }
        }

        if (! function_exists('proc_open')) {
            $this->fail('Refusing to run: proc_open is not available.');
        }

        if (! is_file($this->workerPath())) {
            $this->fail('Refusing to run: the worker script is missing: '.$this->workerPath());
        }
    }

    private function snapshotCounts(): void
    {
        foreach (self::TABLES as $table) {
            $this->countsBefore[$table] = DB::table($table)->count();
        }
    }

    private function createTemporaryDirectory(): void
    {
        $root = realpath(sys_get_temp_dir());

        if ($root === false || ! is_writable($root)) {
            $this->fail('The system temp directory is not usable.');
        }

        $path = $root.'/'.self::DIR_PREFIX.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700)) {
            $this->fail("Could not create the coordination directory {$path}.");
        }

        $this->dir = $path;
    }

    private function createFixtures(): void
    {
        $this->owner = User::factory()->create();
        $this->track('users', $this->owner->id);

        $this->business = Business::factory()->for($this->owner)->create();
        $this->track('businesses', $this->business->id);

        $this->outlet = Outlet::factory()->for($this->business)->create();
        $this->track('outlets', $this->outlet->id);

        $this->category = Category::factory()->for($this->business)->create();
        $this->track('categories', $this->category->id);

        $this->service = new OrderService;
    }

    private function newVariant(): ProductVariant
    {
        $product = Product::factory()->for($this->category)->create();
        $this->track('products', $product->id);

        $variant = ProductVariant::factory()->for($product)->create();
        $this->track('product_variants', $variant->id);

        $this->assertFixturesShareOneBusiness($variant);

        return $variant;
    }

    /**
     * An open order of the test business, created through the service and committed.
     */
    private function newOrder(): Order
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);
        $this->track('orders', $order->id);

        return $order;
    }

    private function assertFixturesShareOneBusiness(ProductVariant $variant): void
    {
        $variant = ProductVariant::query()->with('product')->findOrFail($variant->id);
        $outlet = Outlet::query()->with('business')->findOrFail($this->outlet->id);
        $businessId = (int) $this->business->id;

        $this->assertSame($businessId, (int) $outlet->business_id, 'The outlet must belong to the test business.');
        $this->assertSame($businessId, (int) $variant->product->business_id, 'The variant must belong to a product of the test business.');
        $this->assertSame((int) $this->owner->id, (int) $outlet->business->user_id, 'The test business must be owned by the acting user.');
    }

    private function track(string $table, int $id): void
    {
        $this->tracked[$table][] = $id;
    }

    /**
     * Stop workers, delete exactly the rows this test created, remove the temporary
     * directory and compare table row counts. Best effort: it never throws.
     *
     * @return list<string>
     */
    private function cleanUp(): array
    {
        $problems = [];

        foreach (array_keys($this->workers) as $id) {
            try {
                if (! $this->stopWorker((string) $id)) {
                    $problems[] = "worker {$id} could not be stopped";
                }
            } catch (Throwable $e) {
                $problems[] = "worker {$id}: ".$e->getMessage();
            }
        }

        $this->workers = [];

        foreach ($this->deleteTrackedRows() as $problem) {
            $problems[] = $problem;
        }

        try {
            $this->removeTemporaryDirectory();
        } catch (Throwable $e) {
            $problems[] = 'temporary directory: '.$e->getMessage();
        }

        try {
            foreach ($this->countMismatches() as $problem) {
                $problems[] = $problem;
            }
        } catch (Throwable $e) {
            $problems[] = 'row count check: '.$e->getMessage();
        }

        return $problems;
    }

    /**
     * Foreign-key-safe order: order_items and orders first (orders restrict the
     * business and outlet, order_items restrict the variant), then the catalog, then
     * the owner. Every statement is limited to ids created by this test.
     *
     * @return list<string>
     */
    private function deleteTrackedRows(): array
    {
        $orderIds = $this->tracked['orders'];
        $steps = [];

        if ($orderIds !== []) {
            $steps['order_items'] = fn () => DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            $steps['orders'] = fn () => DB::table('orders')->whereIn('id', $orderIds)->delete();
        }

        foreach (['product_variants', 'products', 'categories', 'outlets', 'businesses', 'users'] as $table) {
            $ids = $this->tracked[$table];

            if ($ids !== []) {
                $steps[$table] = fn () => DB::table($table)->whereIn('id', $ids)->delete();
            }
        }

        $problems = [];

        foreach ($steps as $table => $step) {
            try {
                $step();
            } catch (Throwable $e) {
                $problems[] = "delete from {$table}: ".$e->getMessage();
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function countMismatches(): array
    {
        $problems = [];

        foreach ($this->countsBefore as $table => $before) {
            $now = DB::table($table)->count();

            if ($now !== $before) {
                $problems[] = "{$table} has {$now} rows now but had {$before} before the test";
            }
        }

        return $problems;
    }

    private function removeTemporaryDirectory(): void
    {
        if ($this->dir === null) {
            return;
        }

        $root = realpath(sys_get_temp_dir());
        $dir = realpath($this->dir);

        if ($dir === false) {
            $this->dir = null;

            return;
        }

        if ($root === false || dirname($dir) !== $root || ! str_starts_with(basename($dir), self::DIR_PREFIX)) {
            throw new RuntimeException("Refusing to remove {$dir}: it is not a coordination directory of this test.");
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException("Could not remove {$path}.");
            }
        }

        if (! rmdir($dir)) {
            throw new RuntimeException("Could not remove the directory {$dir}.");
        }

        $this->dir = null;
    }

    /**
     * @param  list<string>  $problems
     */
    private function reportProblems(array $problems): void
    {
        if ($problems === []) {
            return;
        }

        fwrite(STDERR, "\nOrder concurrency test cleanup problems:\n - ".implode("\n - ", $problems)."\nTracked ids: ".json_encode($this->tracked)."\n");
    }

    // Worker processes

    private function workerPath(): string
    {
        return base_path('tests/Support/OrderConcurrencyWorker.php');
    }

    /**
     * The worker inherits the environment of this test process, with the database
     * settings forced to the test database and every URL or socket override removed.
     * The worker checks all of this again after booting.
     *
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'mysql',
            'DB_DATABASE' => 'testing',
            'QART_ORDER_CONCURRENCY_WORKER' => '1',
        ]);

        // proc_open() drops environment variables with an empty value, so an "empty"
        // DB_URL never reaches the worker. Remove any inherited value explicitly.
        unset($environment['DB_URL'], $environment['DB_SOCKET']);

        return $environment;
    }

    /**
     * @param  array{hold?: bool, barrier?: bool}  $options
     */
    private function startWorker(string $id, string $op, int $orderId, ?int $variantId, ?string $quantity, array $options = []): void
    {
        if (isset($this->workers[$id])) {
            $this->fail("Worker id {$id} is already in use.");
        }

        $command = [
            PHP_BINARY,
            $this->workerPath(),
            '--dir='.$this->dir,
            '--id='.$id,
            '--op='.$op,
            '--user='.$this->owner->id,
            '--order='.$orderId,
            '--deadline=40',
        ];

        if ($variantId !== null) {
            $command[] = '--variant='.$variantId;
        }

        if ($quantity !== null) {
            $command[] = '--quantity='.$quantity;
        }

        if ($options['hold'] ?? false) {
            $command[] = '--hold';
        }

        if ($options['barrier'] ?? false) {
            $command[] = '--barrier';
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->dir.'/stdout-'.$id.'.txt', 'w'],
            2 => ['file', $this->dir.'/stderr-'.$id.'.txt', 'w'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, base_path(), $this->workerEnvironment());

        if (! is_resource($process)) {
            $this->fail("Could not start worker {$id}.");
        }

        $this->workers[$id] = ['process' => $process, 'exitCode' => null];
    }

    private function isRunning(string $id): bool
    {
        if ($this->workers[$id]['exitCode'] !== null) {
            return false;
        }

        $status = proc_get_status($this->workers[$id]['process']);

        if ($status['running']) {
            return true;
        }

        // The exit code is only reliable on the first call after the process ended.
        $this->workers[$id]['exitCode'] = $status['exitcode'];

        return false;
    }

    private function waitUntilStopped(string $id, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while ($this->isRunning($id) && microtime(true) < $deadline) {
            usleep(20000);
        }
    }

    private function stopWorker(string $id): bool
    {
        $process = $this->workers[$id]['process'];

        $this->waitUntilStopped($id, 0.5);

        foreach ([15, 9] as $signal) {
            if ($this->isRunning($id)) {
                proc_terminate($process, $signal);
                $this->waitUntilStopped($id, 3);
            }
        }

        if ($this->isRunning($id)) {
            return false;
        }

        proc_close($process);

        return true;
    }

    /**
     * @param  list<string>  $watch
     */
    private function waitForFlag(string $name, int $seconds, array $watch): void
    {
        $path = $this->dir.'/'.$name;
        $deadline = microtime(true) + $seconds;

        while (true) {
            clearstatcache(true, $path);

            if (file_exists($path)) {
                return;
            }

            foreach ($watch as $id) {
                if (! $this->isRunning($id)) {
                    clearstatcache(true, $path);

                    if (file_exists($path)) {
                        return;
                    }

                    $this->failWorker($id, "exited before the flag '{$name}' appeared");
                }
            }

            if (microtime(true) >= $deadline) {
                $this->failWorker($watch[0] ?? 'unknown', "did not raise the flag '{$name}' within {$seconds} seconds");
            }

            usleep(5000);
        }
    }

    private function release(string $id): void
    {
        file_put_contents($this->dir.'/release-'.$id, (string) microtime(true));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readResult(string $id): ?array
    {
        $path = $this->dir.'/result-'.$id.'.json';

        if (! is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function awaitResults(array $ids, int $seconds = self::RESULT_SECONDS): array
    {
        $deadline = microtime(true) + $seconds;
        $results = [];

        while (true) {
            foreach ($ids as $id) {
                if (! isset($results[$id])) {
                    $result = $this->readResult($id);

                    if ($result !== null) {
                        $results[$id] = $result;
                    }
                }
            }

            foreach ($ids as $id) {
                if (isset($results[$id]) || $this->isRunning($id)) {
                    continue;
                }

                // It may have written its result just before it exited.
                $result = $this->readResult($id);

                if ($result === null) {
                    $this->failWorker($id, 'exited (code '.$this->workers[$id]['exitCode'].') without writing a result');
                }

                $results[$id] = $result;
            }

            if (count($results) === count($ids)) {
                return $results;
            }

            if (microtime(true) >= $deadline) {
                $missing = array_values(array_diff($ids, array_keys($results)));

                $this->failWorker($missing[0], "did not finish within {$seconds} seconds (unfinished: ".implode(', ', $missing).')');
            }

            usleep(5000);
        }
    }

    private function failWorker(string $id, string $what): never
    {
        $details = [];

        foreach (['stdout', 'stderr'] as $stream) {
            $file = $this->dir.'/'.$stream.'-'.$id.'.txt';

            if (is_file($file) && filesize($file) > 0) {
                $details[] = $stream.': '.trim(mb_substr((string) file_get_contents($file), 0, 1500));
            }
        }

        $this->fail("Worker {$id} {$what}.".($details === [] ? '' : ' '.implode(' | ', $details)));
    }

    /**
     * Start every worker, wait until all are booted and waiting, then release them together.
     *
     * @param  array<string, string|null>  $quantities  worker id => quantity (null when the operation takes none)
     * @return array<string, array<string, mixed>>
     */
    private function runBarrierRound(string $op, int $orderId, ?int $variantId, array $quantities): array
    {
        $ids = array_map('strval', array_keys($quantities));

        foreach ($quantities as $id => $quantity) {
            $this->startWorker((string) $id, $op, $orderId, $variantId, $quantity, ['barrier' => true]);
        }

        foreach ($ids as $id) {
            $this->waitForFlag('ready-'.$id, self::READY_SECONDS, [$id]);
        }

        file_put_contents($this->dir.'/go', (string) microtime(true));

        $results = $this->awaitResults($ids);

        if (is_file($this->dir.'/go')) {
            unlink($this->dir.'/go');
        }

        return $results;
    }

    /**
     * Pause the holder inside its transaction right after it has locked the order row,
     * prove from the outside that the lock is really held and that the waiter is blocked
     * on it, then release the holder and collect both results. Reaching the checkpoint is
     * not treated as proof. Problems are collected rather than thrown, so the holder is
     * always released and the whole picture ends up in one failure message.
     *
     * @param  array{op: string, variant: int|null, quantity: string|null}  $holder
     * @param  array{op: string, variant: int|null, quantity: string|null}  $waiter
     * @return array{results: array<string, array<string, mixed>>, problems: list<string>, waiting: array{pid: int, seconds: int, statement: string}|null}
     */
    private function runLockScenario(int $orderId, array $holder, array $waiter): array
    {
        $problems = [];

        $this->assertTrue($this->probeOrderLock($orderId), 'Control: the order row must be lockable before the holder starts.');

        $this->startWorker('holder', $holder['op'], $orderId, $holder['variant'], $holder['quantity'], ['hold' => true]);
        $this->waitForFlag('holding-holder', self::READY_SECONDS, ['holder']);

        if ($this->probeOrderLock($orderId)) {
            $problems[] = 'Control failed: the order row was not locked while the holder was paused inside its transaction.';
        }

        $this->startWorker('waiter', $waiter['op'], $orderId, $waiter['variant'], $waiter['quantity']);
        $this->waitForFlag('calling-waiter', self::READY_SECONDS, ['waiter']);

        $waiting = $this->waitForBlockedStatement(1, 8);

        if ($waiting === null) {
            $problems[] = 'No statement was seen waiting for a lock for at least 1 second while the holder was paused: the waiter is not blocked by the holder.';
        } elseif (! str_contains($waiting['statement'], '`orders`') || stripos($waiting['statement'], 'for update') === false) {
            $problems[] = 'The statement seen waiting was not the order lock read: '.$waiting['statement'];
        }

        if (! $this->isStillBlocked('waiter', 1.0)) {
            $problems[] = 'The waiter finished while the holder still held the order lock.';
        }

        $this->release('holder');
        $results = $this->awaitResults(['holder', 'waiter']);

        if (! $this->probeOrderLock($orderId)) {
            $problems[] = 'Control failed: the order row is still locked after both workers finished.';
        }

        return ['results' => $results, 'problems' => $problems, 'waiting' => $waiting];
    }

    // Waiting evidence and the order lock probe

    /**
     * Seen from MySQL itself: a statement of another connection that has been running for
     * at least $minSeconds. The holder of a lock is paused between statements, so a
     * statement that keeps running while the lock is held can only be waiting for it.
     * Without the PROCESS privilege MySQL lists only the threads of the same user, and
     * every worker connects as the test's user.
     *
     * @return array{pid: int, seconds: int, statement: string}|null
     */
    private function waitForBlockedStatement(int $minSeconds, int $withinSeconds): ?array
    {
        $deadline = microtime(true) + $withinSeconds;

        do {
            $rows = DB::select(
                "select id as pid, time as seconds, info as statement from information_schema.processlist where id <> connection_id() and command in ('Query', 'Execute') and info is not null and time >= ? order by time desc",
                [$minSeconds]
            );

            if ($rows !== []) {
                return [
                    'pid' => (int) $rows[0]->pid,
                    'seconds' => (int) $rows[0]->seconds,
                    'statement' => (string) $rows[0]->statement,
                ];
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * True when the worker is still running and has not written a result after $seconds.
     */
    private function isStillBlocked(string $id, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! $this->isRunning($id) || is_file($this->dir.'/result-'.$id.'.json')) {
                return false;
            }

            usleep(20000);
        }

        return true;
    }

    /**
     * True when the order row could be locked at once; false when another transaction
     * holds it. NOWAIT makes MySQL fail immediately instead of waiting.
     */
    private function probeOrderLock(int $orderId): bool
    {
        try {
            $rows = DB::transaction(fn () => DB::select(
                'select id from orders where id = ? for update nowait',
                [$orderId]
            ));
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOWAIT', $e->getMessage(), 'The probe failed for a reason other than the row being locked: '.$e->getMessage());

            return false;
        }

        $this->assertCount(1, $rows, 'The probe must find exactly one order row.');

        return true;
    }

    // Database reads and assertions

    private function orderRow(int $orderId): object
    {
        $row = DB::table('orders')->where('id', $orderId)->first();

        $this->assertNotNull($row, "Order {$orderId} no longer exists.");

        return $row;
    }

    /**
     * @return list<object>
     */
    private function itemsOf(int $orderId): array
    {
        return DB::table('order_items')
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function assertSnapshotMatchesCatalog(object $item, int $variantId): void
    {
        $variant = DB::table('product_variants')->where('id', $variantId)->first();
        $product = DB::table('products')->where('id', $variant->product_id)->first();

        $this->assertSame((string) $product->name, (string) $item->product_name);
        $this->assertSame((string) $variant->name, (string) $item->variant_name);
        $this->assertSame((string) $variant->sku, (string) $item->sku);
        $this->assertSame((string) $variant->price, (string) $item->unit_price);
    }

    /**
     * Every success reports the quantity right after its own merge. If every worker built
     * on the result of the previous one, the sorted quantities grow by exactly the
     * requested amounts, in some order. A lost update shows up as a step that is not a
     * requested amount.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  list<string>  $requested
     */
    private function assertQuantityChain(array $results, array $requested): void
    {
        $returned = array_map(fn ($result) => (int) $result['quantity'], array_values($results));
        sort($returned);

        $steps = [];
        $previous = 0;

        foreach ($returned as $quantity) {
            $steps[] = $quantity - $previous;
            $previous = $quantity;
        }

        $wanted = array_map('intval', $requested);
        sort($steps);
        sort($wanted);

        $this->assertSame($wanted, $steps, 'The reported quantities do not form one chain of the requested amounts: a merge built on a stale quantity.');
    }

    // Result classification

    /**
     * @param  array<string, mixed>  $result
     */
    private function classify(array $result): string
    {
        return match ($result['outcome'] ?? null) {
            'success' => 'success',
            'order_exception' => 'order_rejection',
            'timeout' => 'worker_timeout',
            'refused' => 'refused',
            'error' => $this->classifyError($result),
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function classifyError(array $result): string
    {
        $message = (string) ($result['message'] ?? '');
        $code = isset($result['driver_code']) ? (int) $result['driver_code'] : null;

        if ($code === 1213 || str_contains($message, 'Deadlock found')) {
            return 'deadlock';
        }

        if ($code === 1205 || str_contains($message, 'Lock wait timeout exceeded')) {
            return 'lock_timeout';
        }

        return 'unexpected_error';
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return list<string>
     */
    private function successfulWorkers(array $results): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($results)),
            fn ($id) => $this->classify($results[$id]) === 'success'
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @return list<string>
     */
    private function rejectedWorkers(array $results): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($results)),
            fn ($id) => $this->classify($results[$id]) === 'order_rejection'
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function assertRejectionReasons(array $results, string $reason): void
    {
        foreach ($this->rejectedWorkers($results) as $id) {
            $this->assertSame($reason, $results[$id]['reason'], "Worker {$id} was rejected for an unexpected reason.");
        }
    }

    /**
     * Only a success or an OrderException is an acceptable outcome of a worker.
     * Deadlocks, lock timeouts, worker timeouts and anything else are infrastructure
     * problems and fail the test with the full worker output.
     *
     * @param  array<string, array<string, mixed>>  $results
     */
    private function assertNoInfrastructureErrors(array $results, string $context = ''): void
    {
        $problems = [];

        foreach ($results as $id => $result) {
            $class = $this->classify($result);

            if (! in_array($class, ['success', 'order_rejection'], true)) {
                $problems[] = "{$id} => {$class}: ".json_encode($result, JSON_UNESCAPED_SLASHES);
            }
        }

        $this->assertSame([], $problems, trim("Infrastructure errors, not order rejections. {$context}"));
    }

    /**
     * A worker that began its transaction more than once was retried after a deadlock.
     * The service recovered, but the retry is a finding and is reported as a failure.
     *
     * @param  array<string, array<string, mixed>>  $results
     */
    private function assertNoRetries(array $results): void
    {
        $retried = [];

        foreach ($results as $id => $result) {
            if (isset($result['attempts']) && (int) $result['attempts'] !== 1) {
                $retried[] = "{$id} => attempts {$result['attempts']}";
            }
        }

        $this->assertSame([], $retried, 'Deadlock retries were observed: a worker began its transaction more than once.');
    }
}
