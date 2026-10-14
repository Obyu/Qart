<?php

namespace Tests\Feature;

use App\Exceptions\StockException;
use App\Models\Business;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Real-concurrency tests for StockService.
 *
 * Several PHP processes (tests/Support/StockConcurrencyWorker.php) call the service
 * at the same time against the committed "testing" database. This class does not use
 * RefreshDatabase: it creates its own fixtures, records their exact ids and removes
 * only those rows (plus the stock rows of its own outlet and variants) afterwards.
 * It never migrates, truncates or resets anything.
 *
 * A pass means no violation was observed in these runs. It is not a proof that no
 * interleaving can break the service.
 */
#[Group('concurrency')]
class StockConcurrencyTest extends TestCase
{
    private const TABLES = [
        'users',
        'businesses',
        'outlets',
        'categories',
        'products',
        'product_variants',
        'stock_levels',
        'stock_movements',
    ];

    private const DIR_PREFIX = 'qart-stock-concurrency-';

    private const READY_SECONDS = 30;

    private const RESULT_SECONDS = 40;

    private User $owner;

    private Business $business;

    private Outlet $outlet;

    private Category $category;

    private StockService $stock;

    /** @var array<string, list<int>> */
    private array $tracked = [
        'users' => [],
        'businesses' => [],
        'outlets' => [],
        'categories' => [],
        'products' => [],
        'product_variants' => [],
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

    // Scenario 1: lock held mid-call, a second writer must wait

    public function test_a_second_writer_waits_for_the_row_lock_and_builds_on_the_first_result(): void
    {
        $variant = $this->newVariant();
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, '10.000');

        $this->assertTrue($this->probeRowLock($variant->id), 'Control: the row must be lockable before the holder starts.');

        $this->startWorker('holder', 'adjust', $variant->id, '5.000', ['hold' => true]);
        $this->waitForFlag('holding-holder', self::READY_SECONDS, ['holder']);

        $this->assertFalse($this->probeRowLock($variant->id), 'The row must be locked while the holder is paused inside its transaction.');

        $this->startWorker('waiter', 'adjust', $variant->id, '-3.000');
        $this->waitForFlag('calling-waiter', self::READY_SECONDS, ['waiter']);
        $this->assertStillBlocked('waiter', 1.5);

        $this->release('holder');
        $results = $this->awaitResults(['holder', 'waiter']);

        $ledger = $this->assertLedgerConsistent($variant->id);
        $this->assertNoInfrastructureErrors($results);

        $this->assertSame('15.000', $results['holder']['quantity_after']);
        $this->assertSame('12.000', $results['waiter']['quantity_after'], 'The waiter must build on the result of the holder, not on a stale balance.');
        $this->assertLessThan($results['waiter']['movement_id'], $results['holder']['movement_id']);
        $this->assertSame(1, $results['holder']['attempts']);
        $this->assertSame(1, $results['waiter']['attempts']);
        $this->assertSame('12.000', $this->levelOf($variant->id));
        $this->assertSame(['10.000', '15.000', '12.000'], array_map(fn ($row) => $row->quantity_after, $ledger));
        $this->assertSuccessesMatchLedger($results, $ledger, 1);

        $this->assertTrue($this->probeRowLock($variant->id), 'Control: the row must be lockable again after both transactions finished.');
    }

    // Scenarios 6 and 7: Business deletion against a concurrent stock write

    public function test_business_deletion_vs_stock_write_stock_write_holds_first_and_deletion_is_refused(): void
    {
        $variant = $this->newVariant();
        $outletId = $this->outlet->id;
        $problems = [];

        $this->startWorker('holder', 'opening', $variant->id, '5.000', ['hold' => true]);
        $this->waitForFlag('holding-holder', self::READY_SECONDS, ['holder']);

        if ($this->probeRowLock($variant->id)) {
            $problems[] = 'Control failed: the stock-level row was not locked while the holder was paused inside its transaction.';
        }

        $this->startWorker('deleter', 'delete-business', $variant->id, '0', ['business' => $this->business->id]);
        $this->waitForFlag('calling-deleter', self::READY_SECONDS, ['deleter']);

        $waiting = $this->waitForBlockedStatement(1, 8);

        if ($waiting === null) {
            $problems[] = 'No statement was seen waiting for a lock for at least 1 second while the holder was paused: the deletion is not waiting for the stock write.';
        } elseif (! str_contains($waiting['statement'], '`outlets`')) {
            $problems[] = 'The statement seen waiting was not the outlet lock read: '.$waiting['statement'];
        }

        if (! $this->isStillBlocked('deleter', 1.0)) {
            $problems[] = 'The deletion finished while the stock write still held its locks.';
        }

        $this->release('holder');
        $results = $this->awaitResults(['holder', 'deleter']);

        if ($this->classify($results['holder']) !== 'success') {
            $problems[] = 'The stock write did not succeed: '.json_encode($results['holder']);
        }

        if ($this->classify($results['deleter']) !== 'stock_rejection'
            || ($results['deleter']['reason'] ?? null) !== 'business_has_stock_history') {
            $problems[] = 'The deletion was not refused with business_has_stock_history: '.json_encode($results['deleter']);
        }

        $rows = [
            'business' => DB::table('businesses')->where('id', $this->business->id)->count(),
            'outlet' => DB::table('outlets')->where('id', $outletId)->count(),
            'variant' => DB::table('product_variants')->where('id', $variant->id)->count(),
            'stock_level' => DB::table('stock_levels')->where('outlet_id', $outletId)->where('product_variant_id', $variant->id)->count(),
            'stock_movement' => DB::table('stock_movements')->where('outlet_id', $outletId)->where('product_variant_id', $variant->id)->count(),
        ];

        if ($rows !== array_fill_keys(array_keys($rows), 1)) {
            $problems[] = 'Every one of these rows must still exist exactly once: '.json_encode($rows);
        }

        if ($this->levelOf($variant->id) !== '5.000') {
            $problems[] = 'The stock level must be 5.000, it is '.$this->levelOf($variant->id);
        }

        $this->assertSame(
            [],
            $problems,
            'Stock write first, then Business deletion. Waiting statement seen: '.json_encode($waiting).'. Worker results: '.json_encode($results)
        );

        $ledger = $this->assertLedgerConsistent($variant->id);
        $this->assertSuccessesMatchLedger(['holder' => $results['holder']], $ledger, 0);
    }

    public function test_business_deletion_vs_stock_write_deletion_holds_first_and_the_stock_write_fails_cleanly(): void
    {
        $variant = $this->newVariant();
        $outletId = $this->outlet->id;
        $businessId = $this->business->id;
        $variantId = $variant->id;
        $problems = [];

        $this->startWorker('deleter', 'delete-business', $variantId, '0', ['business' => $businessId, 'hold' => true]);
        $this->waitForFlag('holding-deleter', self::READY_SECONDS, ['deleter']);

        if ($this->probeOutletLock()) {
            $problems[] = 'Control failed: the outlet row was not locked while the deleter was paused inside its transaction.';
        }

        $this->startWorker('writer', 'opening', $variantId, '5.000');
        $this->waitForFlag('calling-writer', self::READY_SECONDS, ['writer']);

        $waiting = $this->waitForBlockedStatement(1, 8);

        if ($waiting === null) {
            $problems[] = 'No statement was seen waiting for a lock for at least 1 second while the deleter was paused: the stock write is not waiting for the deletion.';
        }

        if (! $this->isStillBlocked('writer', 1.0)) {
            $problems[] = 'The stock write finished while the deleter still held the outlet lock.';
        }

        $this->release('deleter');
        $results = $this->awaitResults(['deleter', 'writer']);

        if ($this->classify($results['deleter']) !== 'success') {
            $problems[] = 'The deletion did not succeed: '.json_encode($results['deleter']);
        }

        if ($this->classify($results['writer']) !== 'stock_rejection'
            || ($results['writer']['reason'] ?? null) !== 'not_found') {
            $problems[] = 'The stock write did not fail with not_found: '.json_encode($results['writer']);
        }

        $rows = [
            'business' => DB::table('businesses')->where('id', $businessId)->count(),
            'outlet' => DB::table('outlets')->where('id', $outletId)->count(),
            'variant' => DB::table('product_variants')->where('id', $variantId)->count(),
            'stock_level' => DB::table('stock_levels')->where('outlet_id', $outletId)->count(),
            'stock_movement' => DB::table('stock_movements')->where('outlet_id', $outletId)->count(),
        ];

        if (array_sum($rows) !== 0) {
            $problems[] = 'Nothing of the deleted business may remain, and no stock row may be orphaned: '.json_encode($rows);
        }

        $this->assertSame(
            [],
            $problems,
            'Business deletion first, then the stock write. Waiting statement seen: '.json_encode($waiting).'. Worker results: '.json_encode($results)
        );
    }

    // Scenario 2: parallel adjustments against one level

    public function test_concurrent_adjustments_on_one_stock_level_lose_no_update(): void
    {
        $variant = $this->newVariant();
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, '100.000');

        $quantities = [
            'adjust-1' => '1.500',
            'adjust-2' => '-0.250',
            'adjust-3' => '2.125',
            'adjust-4' => '-1.000',
            'adjust-5' => '0.375',
            'adjust-6' => '0.001',
        ];

        $results = $this->runBarrierRound('adjust', $variant->id, $quantities);

        $ledger = $this->assertLedgerConsistent($variant->id);
        $this->assertNoInfrastructureErrors($results);

        $this->assertCount(6, $this->successfulWorkers($results), 'Every adjustment fits in the stock, so every one must succeed. '.json_encode($results));
        $this->assertSame('102.751', $this->levelOf($variant->id));
        $this->assertSuccessesMatchLedger($results, $ledger, 1);

        $recorded = array_map(fn ($row) => $row->quantity_delta, array_slice($ledger, 1));
        $requested = array_values($quantities);
        sort($recorded);
        sort($requested);
        $this->assertSame($requested, $recorded);
    }

    // Scenario 3: parallel opening-stock attempts

    public function test_concurrent_opening_attempts_record_exactly_one_opening(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $variant = $this->newVariant();
            $quantities = [];

            foreach ([1, 2, 3, 4] as $n) {
                $quantities["open-r{$round}-{$n}"] = "{$n}.000";
            }

            $results = $this->runBarrierRound('opening', $variant->id, $quantities);

            $ledger = $this->assertLedgerConsistent($variant->id);
            $openings = array_filter($ledger, fn ($row) => $row->type === 'opening');
            $this->assertLessThanOrEqual(1, count($openings), "Round {$round}: more than one opening movement exists for one pair.");

            $this->assertNoInfrastructureErrors($results, "Round {$round}.");

            $successes = $this->successfulWorkers($results);
            $this->assertCount(1, $successes, "Round {$round}: exactly one opening must succeed. ".json_encode($results));
            $this->assertCount(3, $this->rejectedWorkers($results), "Round {$round}: the other three must be rejected.");
            $this->assertRejectionReasons($results, 'duplicate_opening');

            $this->assertSame($results[$successes[0]]['quantity_after'], $this->levelOf($variant->id));
            $this->assertSuccessesMatchLedger($results, $ledger, 0);
            $this->assertSame((int) $this->owner->id, (int) $ledger[0]->user_id);
        }
    }

    // Scenario 4: parallel deductions that together exceed the stock

    public function test_concurrent_deductions_never_drive_the_balance_negative(): void
    {
        $variant = $this->newVariant();
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, '10.000');

        $quantities = [];

        foreach (range(1, 8) as $n) {
            $quantities["deduct-{$n}"] = '-3.333';
        }

        $results = $this->runBarrierRound('adjust', $variant->id, $quantities);

        $ledger = $this->assertLedgerConsistent($variant->id);
        $this->assertSame(
            0,
            DB::table('stock_movements')
                ->where('outlet_id', $this->outlet->id)
                ->where('product_variant_id', $variant->id)
                ->where('quantity_after', '<', 0)
                ->count()
        );

        $this->assertNoInfrastructureErrors($results);

        // 10.000 / 3.333 allows exactly three deductions: 6.667, 3.334, 0.001.
        $this->assertCount(3, $this->successfulWorkers($results), 'Exactly three deductions fit. '.json_encode($results));
        $this->assertCount(5, $this->rejectedWorkers($results));
        $this->assertRejectionReasons($results, 'insufficient_stock');

        $this->assertSame('0.001', $this->levelOf($variant->id));
        $this->assertSame(['10.000', '6.667', '3.334', '0.001'], array_map(fn ($row) => $row->quantity_after, $ledger));
        $this->assertSuccessesMatchLedger($results, $ledger, 1);
    }

    // Scenario 5: a lock wait timeout is an infrastructure error, not a stock rejection

    public function test_a_lock_wait_timeout_is_an_infrastructure_error_and_writes_nothing(): void
    {
        $variant = $this->newVariant();
        $this->stock->recordOpening($this->owner, $this->outlet, $variant, '10.000');

        $this->startWorker('holder', 'adjust', $variant->id, '1.000', ['hold' => true]);
        $this->waitForFlag('holding-holder', self::READY_SECONDS, ['holder']);

        // The test process is the contender, with a short session-level lock wait.
        $original = (int) DB::selectOne('select @@session.innodb_lock_wait_timeout as v')->v;
        $caught = null;

        try {
            DB::statement('set session innodb_lock_wait_timeout = 1');

            try {
                $this->stock->adjust($this->owner, $this->outlet, $variant, '-1.000');
            } catch (QueryException $e) {
                $caught = $e;
            } catch (StockException $e) {
                $this->fail('A lock wait timeout must not be reported as a stock rejection (reason: '.$e->reason.').');
            }
        } finally {
            DB::statement('set session innodb_lock_wait_timeout = '.$original);
        }

        $this->assertNotNull($caught, 'The call should have failed with a lock wait timeout while the holder kept the row locked.');
        $this->assertSame(
            'lock_timeout',
            $this->classifyError(['message' => $caught->getMessage(), 'driver_code' => $caught->errorInfo[1] ?? null]),
            'Unexpected error: '.$caught->getMessage()
        );
        $this->assertSame(0, DB::transactionLevel(), 'The failed call must not leave a transaction open.');

        $this->release('holder');
        $results = $this->awaitResults(['holder']);

        $ledger = $this->assertLedgerConsistent($variant->id);
        $this->assertNoInfrastructureErrors($results);
        $this->assertSame('11.000', $this->levelOf($variant->id));
        $this->assertSuccessesMatchLedger($results, $ledger, 1);
    }

    // Scenario 6: the classification used by the assertions above

    public function test_infrastructure_errors_are_never_mistaken_for_stock_rejections(): void
    {
        $cases = [
            'success' => ['outcome' => 'success'],
            'stock_rejection' => ['outcome' => 'stock_exception', 'reason' => 'insufficient_stock'],
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

        $this->stock = app(StockService::class);
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
     * Foreign-key-safe order. Every statement is limited to ids created by this test.
     *
     * @return list<string>
     */
    private function deleteTrackedRows(): array
    {
        $outletIds = $this->tracked['outlets'];
        $variantIds = $this->tracked['product_variants'];
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

        fwrite(STDERR, "\nConcurrency test cleanup problems:\n - ".implode("\n - ", $problems)."\nTracked ids: ".json_encode($this->tracked)."\n");
    }

    // Worker processes

    private function workerPath(): string
    {
        return base_path('tests/Support/StockConcurrencyWorker.php');
    }

    /**
     * @return array<string, string>
     */
    private function workerEnvironment(): array
    {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => 'testing',
            'QART_CONCURRENCY_WORKER' => '1',
        ]);

        // proc_open() drops environment variables with an empty value, so an "empty"
        // DB_URL never reaches the worker. Remove any inherited value explicitly; the
        // worker refuses a non-empty DB_URL and checks the resolved config after boot.
        unset($environment['DB_URL']);

        return $environment;
    }

    /**
     * @param  array{hold?: bool, barrier?: bool, business?: int}  $options
     */
    private function startWorker(string $id, string $op, int $variantId, string $quantity, array $options = []): void
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
            '--outlet='.$this->outlet->id,
            '--variant='.$variantId,
            '--quantity='.$quantity,
            '--deadline=40',
        ];

        if ($options['hold'] ?? false) {
            $command[] = '--hold';
        }

        if ($options['barrier'] ?? false) {
            $command[] = '--barrier';
        }

        if (isset($options['business'])) {
            $command[] = '--business='.$options['business'];
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

    private function assertStillBlocked(string $id, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (! $this->isRunning($id) || is_file($this->dir.'/result-'.$id.'.json')) {
                $this->failWorker($id, 'finished while the stock-level row was still locked by the holder; it should have been waiting');
            }

            usleep(20000);
        }
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
     * @param  array<string, string>  $quantities  worker id => quantity
     * @return array<string, array<string, mixed>>
     */
    private function runBarrierRound(string $op, int $variantId, array $quantities): array
    {
        $ids = array_map('strval', array_keys($quantities));

        foreach ($quantities as $id => $quantity) {
            $this->startWorker((string) $id, $op, $variantId, $quantity, ['barrier' => true]);
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

    // Waiting evidence and the outlet lock probe

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
     * True when the outlet row could be locked at once; false when another transaction
     * holds it. NOWAIT makes MySQL fail immediately instead of waiting.
     */
    private function probeOutletLock(): bool
    {
        try {
            $rows = DB::transaction(fn () => DB::select(
                'select id from outlets where id = ? for update nowait',
                [$this->outlet->id]
            ));
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOWAIT', $e->getMessage(), 'The probe failed for a reason other than the row being locked: '.$e->getMessage());

            return false;
        }

        return count($rows) === 1;
    }

    // Database probes and assertions

    /**
     * True when the stock-level row could be locked at once; false when another
     * transaction holds it. NOWAIT makes MySQL fail immediately instead of waiting.
     */
    private function probeRowLock(int $variantId): bool
    {
        try {
            $rows = DB::transaction(fn () => DB::select(
                'select id from stock_levels where outlet_id = ? and product_variant_id = ? for update nowait',
                [$this->outlet->id, $variantId]
            ));
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOWAIT', $e->getMessage(), 'The probe failed for a reason other than the row being locked: '.$e->getMessage());

            return false;
        }

        $this->assertCount(1, $rows, 'The probe must find exactly one stock-level row.');

        return true;
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
     * Internal consistency only: running balance, never negative, level equals the
     * sum of all deltas. Expected final values are asserted by the callers.
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

    /**
     * @param  array<string, array<string, mixed>>  $results
     * @param  list<object>  $ledger
     */
    private function assertSuccessesMatchLedger(array $results, array $ledger, int $preExisting): void
    {
        $byId = [];

        foreach ($ledger as $row) {
            $byId[$row->id] = $row;
        }

        $movementIds = [];

        foreach ($results as $id => $result) {
            if ($this->classify($result) !== 'success') {
                continue;
            }

            $movementId = $result['movement_id'];

            $this->assertArrayHasKey($movementId, $byId, "Worker {$id} reported movement {$movementId}, which is not in the ledger.");
            $this->assertSame($result['quantity_delta'], $byId[$movementId]->quantity_delta, "Worker {$id}: delta differs from the ledger.");
            $this->assertSame($result['quantity_after'], $byId[$movementId]->quantity_after, "Worker {$id}: quantity_after differs from the ledger.");

            $movementIds[] = $movementId;
        }

        $this->assertSame(count($movementIds), count(array_unique($movementIds)), 'Two workers reported the same movement.');
        $this->assertCount(
            $preExisting + count($movementIds),
            $ledger,
            'The ledger must hold exactly one movement per success and none for rejections or errors.'
        );
    }

    // Result classification

    /**
     * @param  array<string, mixed>  $result
     */
    private function classify(array $result): string
    {
        return match ($result['outcome'] ?? null) {
            'success' => 'success',
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
            fn ($id) => $this->classify($results[$id]) === 'stock_rejection'
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
     * Only a success or a StockException is an acceptable outcome of a worker.
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

            if (! in_array($class, ['success', 'stock_rejection'], true)) {
                $problems[] = "{$id} => {$class}: ".json_encode($result, JSON_UNESCAPED_SLASHES);
            }
        }

        $this->assertSame([], $problems, trim("Infrastructure errors, not stock rejections. {$context}"));
    }
}
