<?php

namespace Tests\Feature;

use App\Exceptions\OrderException;
use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real-concurrency tests for OrderService::completeSale() (Stage 2d).
 *
 * Several PHP processes (tests/Support/OrderConcurrencyWorker.php, operation
 * "complete-sale") call the service at the same time against the committed
 * "testing" database. This class does not use RefreshDatabase: it creates its own
 * fixtures, records their exact ids and removes only those rows afterwards. Stock
 * movements and levels are deleted with the query builder, children before parents.
 * It never migrates, truncates or resets anything.
 *
 * A pass means no violation was observed in these runs. It is not a proof that no
 * interleaving can break the service. Deadlock retries and infrastructure errors fail
 * the test with the full worker output; they are never hidden.
 */
#[Group('concurrency')]
class OrderSaleConcurrencyTest extends TestCase
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
        'stock_levels',
        'stock_movements',
    ];

    /** The worker only accepts coordination directories with this prefix. */
    private const DIR_PREFIX = 'qart-order-concurrency-';

    private const READY_SECONDS = 30;

    private const RESULT_SECONDS = 40;

    private User $owner;

    private Business $business;

    private Outlet $outlet;

    private Category $category;

    private OrderService $service;

    private StockService $stock;

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

    // Scenario 1: several processes sell the same order

    public function test_concurrent_sales_of_one_order_deduct_the_stock_exactly_once(): void
    {
        $a = $this->newVariant();
        $b = $this->newVariant();
        $this->open($a, '10');
        $this->open($b, '5');
        $order = $this->orderWith([[$a, 3], [$b, 2]]);

        $workers = [];

        foreach (range(1, 4) as $n) {
            $workers["sale-{$n}"] = ['op' => 'complete-sale', 'order' => (int) $order->id];
        }

        $results = $this->runBarrierRound($workers);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);

        $winners = $this->workersWith($results, 'success');
        $losers = $this->workersWith($results, 'order_rejection');

        $this->assertCount(1, $winners, 'Exactly one sale may win. '.json_encode($results));
        $this->assertCount(3, $losers, 'The other three must be refused as not open. '.json_encode($results));
        $this->assertReasons($results, $losers, OrderException::REASON_ORDER_NOT_OPEN);
        $this->assertSame('completed', $results[$winners[0]]['status'] ?? null);

        $this->assertOrderCompleted($order->id);
        $this->assertSame('7.000', $this->levelOf($a->id));
        $this->assertSame('3.000', $this->levelOf($b->id));

        $sales = $this->saleMovementsOf($order->id);

        $this->assertCount(2, $sales, 'One sale movement per item, written once.');

        foreach ($sales as $movement) {
            $this->assertSame(-1, bccomp($movement->quantity_delta, '0', 3), 'A sale movement must be negative.');
        }

        $this->assertSame(['10.000', '7.000'], array_map(fn ($row) => $row->quantity_after, $this->assertLedgerConsistent($a->id)));
        $this->assertSame(['5.000', '3.000'], array_map(fn ($row) => $row->quantity_after, $this->assertLedgerConsistent($b->id)));
    }

    // Scenario 2: five orders compete for stock that covers only three of them

    public function test_orders_competing_for_limited_stock_never_oversell(): void
    {
        $variant = $this->newVariant();
        $this->open($variant, '3');

        $orders = [];
        $workers = [];

        foreach (range(1, 5) as $n) {
            $order = $this->orderWith([[$variant, 1]]);
            $orders["sale-{$n}"] = $order;
            $workers["sale-{$n}"] = ['op' => 'complete-sale', 'order' => (int) $order->id];
        }

        $results = $this->runBarrierRound($workers);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);

        $winners = $this->workersWith($results, 'success');
        $losers = $this->workersWith($results, 'stock_rejection');

        $this->assertCount(3, $winners, 'Exactly three sales fit in the stock. '.json_encode($results));
        $this->assertCount(2, $losers, 'The other two must be refused for stock. '.json_encode($results));
        $this->assertReasons($results, $losers, StockException::REASON_INSUFFICIENT_STOCK);

        foreach ($losers as $id) {
            $this->assertSame((int) $variant->id, (int) ($results[$id]['product_variant_id'] ?? 0), "Worker {$id} must name the variant.");
        }

        foreach ($orders as $id => $order) {
            if (in_array((string) $id, $winners, true)) {
                $this->assertOrderCompleted($order->id);
                $this->assertCount(1, $this->saleMovementsOf($order->id), "Order of {$id} was sold, so it needs one movement.");
            } else {
                $this->assertOrderOpen($order->id);
                $this->assertCount(0, $this->saleMovementsOf($order->id), "Order of {$id} was refused, so it needs no movement.");
            }
        }

        $ledger = $this->assertLedgerConsistent($variant->id);

        $this->assertSame('0.000', $this->levelOf($variant->id));
        $this->assertSame(['3.000', '2.000', '1.000', '0.000'], array_map(fn ($row) => $row->quantity_after, $ledger));
    }

    // Scenario 3: orders that hold the same variants in opposite item order

    public function test_sales_with_opposite_item_order_lock_stock_in_variant_order_and_all_succeed(): void
    {
        $a = $this->newVariant();
        $b = $this->newVariant();

        $this->assertLessThan((int) $b->id, (int) $a->id, 'Control: variant A must have the lower id.');

        $this->open($a, '10');
        $this->open($b, '10');

        $orders = [
            'sale-1' => $this->orderWith([[$a, 1], [$b, 2]]),
            'sale-2' => $this->orderWith([[$b, 1], [$a, 2]]),
            'sale-3' => $this->orderWith([[$a, 3], [$b, 1]]),
            'sale-4' => $this->orderWith([[$b, 3], [$a, 1]]),
        ];

        foreach (['sale-2', 'sale-4'] as $id) {
            $first = $this->itemsOf($orders[$id]->id)[0];

            $this->assertSame((int) $b->id, (int) $first->product_variant_id, "Control: the first item of {$id} must be variant B.");
        }

        $workers = [];

        foreach ($orders as $id => $order) {
            $workers[$id] = ['op' => 'complete-sale', 'order' => (int) $order->id];
        }

        $results = $this->runBarrierRound($workers);

        $this->assertNoInfrastructureErrors($results);
        $this->assertNoRetries($results);
        $this->assertCount(4, $this->workersWith($results, 'success'), 'All four sales fit in the stock. '.json_encode($results));

        foreach ($orders as $order) {
            $this->assertOrderCompleted($order->id);
            $this->assertCount(2, $this->saleMovementsOf($order->id));
        }

        $this->assertSame('3.000', $this->levelOf($a->id));
        $this->assertSame('3.000', $this->levelOf($b->id));
        $this->assertCount(5, $this->assertLedgerConsistent($a->id), 'One opening and four sales.');
        $this->assertCount(5, $this->assertLedgerConsistent($b->id), 'One opening and four sales.');
    }

    // Scenario 4: a sale holds the order lock, an addition waits for it

    public function test_a_sale_holding_the_order_lock_makes_a_waiting_addition_fail_cleanly(): void
    {
        $existing = $this->newVariant();
        $added = $this->newVariant();
        $this->open($existing, '10');
        $order = $this->orderWith([[$existing, 2]]);

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'complete-sale', 'variant' => null, 'quantity' => null],
            ['op' => 'add-item', 'variant' => $added->id, 'quantity' => '4'],
        );

        $results = $run['results'];

        $problems = array_merge(
            $run['problems'],
            array_filter([
                $this->outcomeProblem('holder', $results['holder'], 'success'),
                $this->outcomeProblem('waiter', $results['waiter'], 'order_rejection', OrderException::REASON_ORDER_NOT_OPEN),
            ]),
            $this->retryProblems($results),
        );

        if (($results['holder']['status'] ?? null) !== 'completed') {
            $problems[] = 'The sale did not report a completed order: '.json_encode($results['holder']);
        }

        $this->assertSame(
            [],
            $problems,
            'Sale holding the order lock first, then a waiting addition. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );

        $this->assertOrderCompleted($order->id);

        $items = $this->itemsOf($order->id);

        $this->assertCount(1, $items, 'The refused addition must leave no item.');
        $this->assertSame((int) $existing->id, (int) $items[0]->product_variant_id);
        $this->assertSame(2, (int) $items[0]->quantity);

        $this->assertSame('8.000', $this->levelOf($existing->id));
        $this->assertCount(1, $this->saleMovementsOf($order->id));
        $this->assertFalse(DB::table('stock_levels')->where('product_variant_id', $added->id)->exists());
        $this->assertSame(0, DB::table('stock_movements')->where('product_variant_id', $added->id)->count());
    }

    // Scenario 5: an addition holds the order lock on an empty order, a sale waits for it

    public function test_an_addition_holding_the_order_lock_on_an_empty_order_is_seen_by_a_waiting_sale(): void
    {
        $variant = $this->newVariant();
        $this->open($variant, '10');
        $order = $this->newOrder();

        $this->assertSame([], $this->itemsOf($order->id), 'Control: the order must be empty before the scenario starts.');

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'add-item', 'variant' => $variant->id, 'quantity' => '3'],
            ['op' => 'complete-sale', 'variant' => null, 'quantity' => null],
        );

        $results = $run['results'];

        $problems = array_merge(
            $run['problems'],
            array_filter([
                $this->outcomeProblem('holder', $results['holder'], 'success'),
                $this->outcomeProblem('waiter', $results['waiter'], 'success'),
            ]),
            $this->retryProblems($results),
        );

        if ((int) ($results['holder']['quantity'] ?? 0) !== 3) {
            $problems[] = 'The addition did not report quantity 3: '.json_encode($results['holder']);
        }

        if (($results['waiter']['status'] ?? null) !== 'completed') {
            $problems[] = 'The waiting sale did not complete the order (an empty_sale or empty_order rejection would mean it did not see the new item): '.json_encode($results['waiter']);
        }

        $this->assertSame(
            [],
            $problems,
            'Addition holding the order lock first on an empty order, then a waiting sale. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );

        $this->assertOrderCompleted($order->id);

        $items = $this->itemsOf($order->id);

        $this->assertCount(1, $items);
        $this->assertSame((int) $variant->id, (int) $items[0]->product_variant_id);
        $this->assertSame(3, (int) $items[0]->quantity);

        $sales = $this->saleMovementsOf($order->id);

        $this->assertCount(1, $sales, 'The new item must have its sale movement.');
        $this->assertSame('-3.000', $sales[0]->quantity_delta);
        $this->assertSame('7.000', $sales[0]->quantity_after);
        $this->assertSame('7.000', $this->levelOf($variant->id));
        $this->assertLedgerConsistent($variant->id);
    }

    // Scenario 6: a sale holds the order lock, a plain completion waits for it

    public function test_a_sale_holding_the_order_lock_makes_a_waiting_plain_completion_fail_cleanly(): void
    {
        $variant = $this->newVariant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 3]]);

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'complete-sale', 'variant' => null, 'quantity' => null],
            ['op' => 'complete', 'variant' => null, 'quantity' => null],
        );

        $results = $run['results'];

        $problems = array_merge(
            $run['problems'],
            array_filter([
                $this->outcomeProblem('holder', $results['holder'], 'success'),
                $this->outcomeProblem('waiter', $results['waiter'], 'order_rejection', OrderException::REASON_ORDER_NOT_OPEN),
            ]),
            $this->retryProblems($results),
        );

        $this->assertSame(
            [],
            $problems,
            'Sale holding the order lock first, then a waiting plain completion. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );

        $this->assertOrderCompleted($order->id);
        $this->assertSame('7.000', $this->levelOf($variant->id));
        $this->assertCount(1, $this->saleMovementsOf($order->id), 'The stock must be deducted exactly once.');
        $this->assertLedgerConsistent($variant->id);
    }

    // Scenario 7: a plain completion holds the order lock, a sale waits for it

    public function test_a_plain_completion_holding_the_order_lock_makes_a_waiting_sale_fail_without_deducting(): void
    {
        $variant = $this->newVariant();
        $this->open($variant, '10');
        $order = $this->orderWith([[$variant, 3]]);

        $run = $this->runLockScenario(
            $order->id,
            ['op' => 'complete', 'variant' => null, 'quantity' => null],
            ['op' => 'complete-sale', 'variant' => null, 'quantity' => null],
        );

        $results = $run['results'];

        $problems = array_merge(
            $run['problems'],
            array_filter([
                $this->outcomeProblem('holder', $results['holder'], 'success'),
                $this->outcomeProblem('waiter', $results['waiter'], 'order_rejection', OrderException::REASON_ORDER_NOT_OPEN),
            ]),
            $this->retryProblems($results),
        );

        $this->assertSame(
            [],
            $problems,
            'Plain completion holding the order lock first, then a waiting sale. Waiting statement seen: '.json_encode($run['waiting']).'. Worker results: '.json_encode($results)
        );

        $this->assertOrderCompleted($order->id);
        $this->assertSame('10.000', $this->levelOf($variant->id), 'The refused sale must not deduct anything.');
        $this->assertCount(0, $this->saleMovementsOf($order->id));
        $this->assertLedgerConsistent($variant->id);
    }

    // Scenario 8: the classification used by the assertions above

    public function test_worker_failures_are_never_mistaken_for_rejections(): void
    {
        $cases = [
            'success' => ['outcome' => 'success'],
            'order_rejection' => ['outcome' => 'order_exception', 'reason' => 'order_not_open'],
            'stock_rejection' => ['outcome' => 'stock_exception', 'reason' => 'insufficient_stock', 'product_variant_id' => 1],
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

        $this->assertNoInfrastructureErrors([
            'a' => $cases['success'],
            'b' => $cases['order_rejection'],
            'c' => $cases['stock_rejection'],
        ]);

        foreach (['deadlock', 'lock_timeout', 'unexpected_error', 'worker_timeout', 'refused'] as $name) {
            try {
                $this->assertNoInfrastructureErrors(['w' => $cases[$name]]);
            } catch (ExpectationFailedException) {
                continue;
            }

            $this->fail("A {$name} result must be reported as an infrastructure error.");
        }
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

        if (! str_contains((string) file_get_contents($this->workerPath()), "'complete-sale'")) {
            $this->fail('Refusing to run: the worker script does not support the complete-sale operation.');
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
        $this->stock = new StockService;
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

    private function newOrder(): Order
    {
        $order = $this->service->createOrder($this->owner, $this->business, $this->outlet);
        $this->track('orders', $order->id);

        return $order;
    }

    private function open(ProductVariant $variant, string $quantity): void
    {
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, $quantity);
    }

    /**
     * An open order of the owner's outlet holding the given lines, built through OrderService.
     *
     * @param  list<array{0: ProductVariant, 1: int}>  $lines
     */
    private function orderWith(array $lines): Order
    {
        $order = $this->newOrder();

        foreach ($lines as [$variant, $quantity]) {
            $this->service->addItem($this->owner, $order, $variant, $quantity);
        }

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
     * Children before parents. Sale movements pin their order items and the outlet and
     * variant through RESTRICT keys, so they and the stock levels go first. They are
     * removed with the query builder: the model refuses to delete movements.
     *
     * @return list<string>
     */
    private function deleteTrackedRows(): array
    {
        $outletIds = $this->tracked['outlets'];
        $variantIds = $this->tracked['product_variants'];
        $orderIds = $this->tracked['orders'];
        $steps = [];

        if ($outletIds !== [] && $variantIds !== []) {
            $steps['stock_movements'] = fn () => DB::table('stock_movements')
                ->whereIn('outlet_id', $outletIds)
                ->whereIn('product_variant_id', $variantIds)
                ->delete();

            $steps['stock_levels'] = fn () => DB::table('stock_levels')
                ->whereIn('outlet_id', $outletIds)
                ->whereIn('product_variant_id', $variantIds)
                ->delete();
        }

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

        fwrite(STDERR, "\nOrder sale concurrency test cleanup problems:\n - ".implode("\n - ", $problems)."\nTracked ids: ".json_encode($this->tracked)."\n");
    }

    // Worker processes

    private function workerPath(): string
    {
        return base_path('tests/Support/OrderConcurrencyWorker.php');
    }

    /**
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

    // Rounds

    /**
     * Start one worker per entry, wait until all are ready, release them together and
     * collect the results. Each worker names its own order.
     *
     * @param  array<string, array{op: string, order: int}>  $workers
     * @return array<string, array<string, mixed>>
     */
    private function runBarrierRound(array $workers): array
    {
        $ids = array_map('strval', array_keys($workers));

        foreach ($workers as $id => $spec) {
            $this->startWorker((string) $id, $spec['op'], $spec['order'], null, null, ['barrier' => true]);
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
     * The holder pauses right after it has locked the order row. The waiter then starts
     * and must be seen blocked on that lock before the holder is released.
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

    /**
     * Seen from MySQL itself: a statement of another connection that has been running
     * for at least $minSeconds. The holder of a lock is paused between statements, so a
     * statement that keeps running while the lock is held can only be waiting for it.
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

    /**
     * @return list<object>
     */
    private function saleMovementsOf(int $orderId): array
    {
        return DB::table('stock_movements')
            ->join('order_items', 'order_items.id', '=', 'stock_movements.order_item_id')
            ->where('order_items.order_id', $orderId)
            ->where('stock_movements.type', 'sale')
            ->orderBy('stock_movements.id')
            ->select('stock_movements.*')
            ->get()
            ->all();
    }

    private function assertOrderCompleted(int $orderId): void
    {
        $row = $this->orderRow($orderId);

        $this->assertSame('completed', $row->status, "Order {$orderId} must be completed.");
        $this->assertNotNull($row->completed_at, "Order {$orderId} must have a completed_at.");
    }

    private function assertOrderOpen(int $orderId): void
    {
        $row = $this->orderRow($orderId);

        $this->assertSame('open', $row->status, "Order {$orderId} must still be open.");
        $this->assertNull($row->completed_at, "Order {$orderId} must have no completed_at.");
    }

    private function levelOf(int $variantId): string
    {
        return (string) (DB::table('stock_levels')
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $variantId)
            ->value('quantity') ?? '0.000');
    }

    /**
     * @return list<object>
     */
    private function ledger(int $variantId): array
    {
        return DB::table('stock_movements')
            ->where('outlet_id', $this->outlet->id)
            ->where('product_variant_id', $variantId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Every row's quantity_after must equal the running sum of the deltas, never be
     * negative, and the final sum must equal the stock level.
     *
     * @return list<object>
     */
    private function assertLedgerConsistent(int $variantId): array
    {
        $ledger = $this->ledger($variantId);
        $running = '0.000';

        foreach ($ledger as $row) {
            $running = bcadd($running, $row->quantity_delta, 3);

            $this->assertSame($running, $row->quantity_after, "Movement {$row->id}: quantity_after must equal the running balance of the ledger.");
            $this->assertGreaterThanOrEqual(0, bccomp($row->quantity_after, '0', 3), "Movement {$row->id}: the balance became negative.");
        }

        $this->assertSame($running, $this->levelOf($variantId), 'stock_levels.quantity must equal the sum of all movement deltas.');

        return $ledger;
    }

    // Worker results

    /**
     * @param  array<string, mixed>  $result
     */
    private function classify(array $result): string
    {
        return match ($result['outcome'] ?? null) {
            'success' => 'success',
            'order_exception' => 'order_rejection',
            'stock_exception' => 'stock_rejection',
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
    private function workersWith(array $results, string $class): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($results)),
            fn ($id) => $this->classify($results[$id]) === $class
        ));
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @param  list<string>  $ids
     */
    private function assertReasons(array $results, array $ids, string $reason): void
    {
        foreach ($ids as $id) {
            $this->assertSame($reason, $results[$id]['reason'] ?? null, "Worker {$id}: ".json_encode($results[$id]));
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function outcomeProblem(string $who, array $result, string $class, ?string $reason = null): ?string
    {
        $actual = $this->classify($result);

        if ($actual !== $class) {
            return "{$who}: expected {$class}, got {$actual}: ".json_encode($result);
        }

        if ($reason !== null && ($result['reason'] ?? null) !== $reason) {
            return "{$who}: expected reason {$reason}: ".json_encode($result);
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function assertNoInfrastructureErrors(array $results, string $context = ''): void
    {
        $problems = [];

        foreach ($results as $id => $result) {
            $class = $this->classify($result);

            if (! in_array($class, ['success', 'order_rejection', 'stock_rejection'], true)) {
                $problems[] = "{$id} => {$class}: ".json_encode($result, JSON_UNESCAPED_SLASHES);
            }
        }

        $this->assertSame([], $problems, trim("Infrastructure errors, not order or stock rejections. {$context}"));
    }

    /**
     * Only outermost transactions are counted by the worker, so more than one means a
     * deadlock retry.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @return list<string>
     */
    private function retryProblems(array $results): array
    {
        $problems = [];

        foreach ($results as $id => $result) {
            if (isset($result['attempts']) && (int) $result['attempts'] !== 1) {
                $problems[] = "Deadlock retry observed: worker {$id} began its transaction {$result['attempts']} times.";
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function assertNoRetries(array $results): void
    {
        $this->assertSame([], $this->retryProblems($results), 'Deadlock retries were observed: a worker began its transaction more than once.');
    }
}
