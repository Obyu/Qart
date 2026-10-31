<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\StockService;
use Illuminate\Database\Seeder;

/**
 * Demo data for a local presentation.
 *
 * Everything that touches stock, orders or payments goes through the
 * application services, so ledgers, price snapshots and constraints stay
 * consistent. WithoutModelEvents is deliberately not used: Product derives
 * its business_id in a saving event.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string, ProductVariant> */
    private array $variants = [];

    private OrderService $orders;

    private PaymentService $payments;

    public function run(): void
    {
        if (User::query()->exists()) {
            $this->command?->error('DemoSeeder stopped: the database already has users. Run it only after migrate:fresh.');

            return;
        }

        $this->orders = new OrderService;
        $this->payments = new PaymentService($this->orders);
        $stock = new StockService;

        $owner = User::factory()->create([
            'name' => 'Joby',
            'email' => 'joby@gmail.com',
        ]);

        $business = $owner->businesses()->create(['name' => 'Kopi Senja']);
        $main = $business->outlets()->create(['name' => 'Outlet Utama']);
        $branch = $business->outlets()->create(['name' => 'Outlet Cabang']);

        $this->createCatalog($business);

        foreach ([$main, $branch] as $outlet) {
            foreach ($this->variants as $variant) {
                $stock->recordOpening($owner, $outlet, $variant, '40', 'Stok awal demo');
            }
        }

        $this->createSales($owner, $business, $main, $branch);

        $this->command?->info('DemoSeeder done. Login: joby@gmail.com / password');
    }

    private function createCatalog(Business $business): void
    {
        $catalog = [
            'Kopi' => [
                'Kopi Susu' => [['Regular', 'KOP-001', '18000.00'], ['Large', 'KOP-002', '25000.00']],
                'Americano' => [['Regular', 'KOP-003', '15000.00'], ['Large', 'KOP-004', '20000.00']],
            ],
            'Non-Kopi' => [
                'Es Teh Manis' => [['Regular', 'NK-001', '8000.00'], ['Large', 'NK-002', '12000.00']],
                'Matcha Latte' => [['Regular', 'NK-003', '24000.00']],
            ],
            'Makanan' => [
                'Roti Bakar' => [['Cokelat', 'MK-001', '15000.00'], ['Keju', 'MK-002', '17000.00']],
            ],
        ];

        foreach ($catalog as $categoryName => $products) {
            $category = $business->categories()->create(['name' => $categoryName]);

            foreach ($products as $productName => $variants) {
                $product = $category->products()->create(['name' => $productName]);

                foreach ($variants as [$variantName, $sku, $price]) {
                    $this->variants[$sku] = $product->variants()->create([
                        'name' => $variantName,
                        'sku' => $sku,
                        'price' => $price,
                    ]);
                }
            }
        }
    }

    private function createSales(User $owner, Business $business, Outlet $main, Outlet $branch): void
    {
        // Cash, total 36.000, received 50.000
        $this->cashSale($owner, $business, $main, [['KOP-001', 2]], '50000.00');

        // Cash, total 37.000, received 40.000
        $this->cashSale($owner, $business, $main, [['KOP-004', 1], ['MK-002', 1]], '40000.00');

        // QRIS, total 36.000
        $this->qrisSale($owner, $business, $main, [['NK-002', 3]], 'QR-DEMO-001');

        // QRIS, total 49.000
        $this->qrisSale($owner, $business, $main, [['NK-003', 1], ['KOP-002', 1]], 'QR-DEMO-002');

        // Split equally, total 80.000: participant 1 pays cash, participant 2 pays QRIS
        $order = $this->openOrder($owner, $business, $main, [['KOP-002', 2], ['MK-001', 2]]);
        $billed = $this->payments->createBill(
            $owner,
            $order,
            PaymentService::MODE_SPLIT_EQUAL,
            ['Peserta 1', 'Peserta 2'],
        );
        $participants = $billed->participants()->orderBy('position')->get();

        $this->payments->payCash($owner, $billed, '50000.00', (int) $participants[0]->id);
        $pending = $this->payments->createQrisPayment(
            $owner,
            $billed,
            participantId: (int) $participants[1]->id,
            reference: 'QR-DEMO-003',
        );
        $this->payments->confirmQrisPayment($owner, $billed, (int) $pending->id);

        // Second outlet, cash, total 26.000, received 30.000
        $this->cashSale($owner, $business, $branch, [['KOP-001', 1], ['NK-001', 1]], '30000.00');

        // Cancelled before any payment
        $order = $this->openOrder($owner, $business, $main, [['KOP-003', 1]]);
        $this->orders->cancelOrder($owner, $order, 'Pelanggan membatalkan pesanan');

        // Left open with a pending QRIS payment
        $order = $this->openOrder($owner, $business, $main, [['NK-003', 1]]);
        $this->payments->createBill($owner, $order, PaymentService::MODE_DIRECT);
        $this->payments->createQrisPayment($owner, $order, reference: 'QR-DEMO-PENDING');
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $lines
     */
    private function openOrder(User $owner, Business $business, Outlet $outlet, array $lines): Order
    {
        $order = $this->orders->createOrder($owner, $business, $outlet);

        foreach ($lines as [$sku, $quantity]) {
            $this->orders->addItem($owner, $order, $this->variants[$sku], $quantity);
        }

        return $order;
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $lines
     */
    private function cashSale(User $owner, Business $business, Outlet $outlet, array $lines, string $received): void
    {
        $order = $this->openOrder($owner, $business, $outlet, $lines);
        $this->payments->createBill($owner, $order, PaymentService::MODE_DIRECT);
        $this->payments->payCash($owner, $order, $received);
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $lines
     */
    private function qrisSale(User $owner, Business $business, Outlet $outlet, array $lines, string $reference): void
    {
        $order = $this->openOrder($owner, $business, $outlet, $lines);
        $this->payments->createBill($owner, $order, PaymentService::MODE_DIRECT);
        $pending = $this->payments->createQrisPayment($owner, $order, reference: $reference);
        $this->payments->confirmQrisPayment($owner, $order, (int) $pending->id);
    }
}
