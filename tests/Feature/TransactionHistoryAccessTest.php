<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Filament\Pages\TransactionHistory;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\User;
use App\Services\StaffService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Throwable;

class TransactionHistoryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        // Filament only skips the FilamentUser check when app.env is "local".
        config(['app.env' => 'production']);
    }

    /**
     * Two businesses. The first has two outlets (main, sibling), the second has one.
     * Every outlet has exactly one order, so a leak shows up as a record that
     * should be absent, and every "cannot see" check has a "can see" control.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $owner = User::factory()->create();
        $business = Business::factory()->for($owner)->create();
        $main = Outlet::factory()->for($business)->create();
        $sibling = Outlet::factory()->for($business)->create();

        $foreignOwner = User::factory()->create();
        $foreignBusiness = Business::factory()->for($foreignOwner)->create();
        $foreignOutlet = Outlet::factory()->for($foreignBusiness)->create();

        $staff = app(StaffService::class);

        $make = fn (User $by, Business $in, string $email, StaffRole $role, ?Outlet $outlet): User => $staff->create($by, [
            'name' => ucfirst($role->value),
            'email' => $email,
            'password' => 'history-password-1',
            'business_id' => $in->id,
            'role' => $role->value,
            'outlet_id' => $outlet?->id,
        ]);

        return [
            'owner' => $owner,
            'foreignOwner' => $foreignOwner,
            'admin' => $make($owner, $business, 'admin@example.test', StaffRole::BusinessAdmin, null),
            'cashierMain' => $make($owner, $business, 'cashier-main@example.test', StaffRole::Cashier, $main),
            'cashierSibling' => $make($owner, $business, 'cashier-sibling@example.test', StaffRole::Cashier, $sibling),
            'kitchen' => $make($owner, $business, 'kitchen@example.test', StaffRole::Kitchen, $main),
            'foreignAdmin' => $make($foreignOwner, $foreignBusiness, 'foreign-admin@example.test', StaffRole::BusinessAdmin, null),
            'mainOrder' => $this->orderAt($business, $main),
            'siblingOrder' => $this->orderAt($business, $sibling),
            'foreignOrder' => $this->orderAt($foreignBusiness, $foreignOutlet),
        ];
    }

    private function orderAt(Business $business, Outlet $outlet): Order
    {
        return Order::factory()->for($business)->create(['outlet_id' => $outlet->id]);
    }

    private function historyAs(User $user)
    {
        $this->actingAs($user);

        return Livewire::test(TransactionHistory::class);
    }

    public function test_owner_sees_every_outlet_of_their_own_business_but_nothing_of_another(): void
    {
        $w = $this->world();

        $this->historyAs($w['owner'])
            ->assertCanSeeTableRecords([$w['mainOrder'], $w['siblingOrder']])
            ->assertCanNotSeeTableRecords([$w['foreignOrder']]);

        $this->historyAs($w['foreignOwner'])
            ->assertCanSeeTableRecords([$w['foreignOrder']])
            ->assertCanNotSeeTableRecords([$w['mainOrder'], $w['siblingOrder']]);
    }

    public function test_business_admin_sees_every_outlet_of_their_business_only(): void
    {
        $w = $this->world();

        $this->historyAs($w['admin'])
            ->assertCanSeeTableRecords([$w['mainOrder'], $w['siblingOrder']])
            ->assertCanNotSeeTableRecords([$w['foreignOrder']]);

        $this->historyAs($w['foreignAdmin'])
            ->assertCanSeeTableRecords([$w['foreignOrder']])
            ->assertCanNotSeeTableRecords([$w['mainOrder'], $w['siblingOrder']]);
    }

    public function test_cashier_sees_only_the_orders_of_their_own_outlet(): void
    {
        $w = $this->world();

        $this->historyAs($w['cashierMain'])
            ->assertCanSeeTableRecords([$w['mainOrder']])
            ->assertCanNotSeeTableRecords([$w['siblingOrder'], $w['foreignOrder']]);

        $this->historyAs($w['cashierSibling'])
            ->assertCanSeeTableRecords([$w['siblingOrder']])
            ->assertCanNotSeeTableRecords([$w['mainOrder'], $w['foreignOrder']]);
    }

    public function test_an_owner_without_a_business_opens_the_page_and_sees_nothing(): void
    {
        $w = $this->world();
        $empty = User::factory()->create();

        $this->historyAs($empty)
            ->assertCanNotSeeTableRecords([$w['mainOrder'], $w['siblingOrder'], $w['foreignOrder']]);
    }

    public function test_the_table_resolves_only_records_inside_the_users_scope(): void
    {
        $w = $this->world();

        $cases = [
            'owner' => [$w['owner'], [$w['mainOrder'], $w['siblingOrder']], [$w['foreignOrder']]],
            'business admin' => [$w['admin'], [$w['mainOrder'], $w['siblingOrder']], [$w['foreignOrder']]],
            'cashier of the main outlet' => [$w['cashierMain'], [$w['mainOrder']], [$w['siblingOrder'], $w['foreignOrder']]],
            'cashier of the sibling outlet' => [$w['cashierSibling'], [$w['siblingOrder']], [$w['mainOrder'], $w['foreignOrder']]],
            'owner of the other business' => [$w['foreignOwner'], [$w['foreignOrder']], [$w['mainOrder'], $w['siblingOrder']]],
            'admin of the other business' => [$w['foreignAdmin'], [$w['foreignOrder']], [$w['mainOrder'], $w['siblingOrder']]],
        ];

        foreach ($cases as $label => [$user, $inside, $outside]) {
            $page = $this->historyAs($user)->instance();

            foreach ($inside as $order) {
                $record = $page->getTableRecord((string) $order->getKey());

                $this->assertInstanceOf(Order::class, $record, "{$label}: order #{$order->getKey()} must resolve");
                $this->assertSame((int) $order->getKey(), (int) $record->getKey(), $label);
            }

            foreach ($outside as $order) {
                $this->assertNull(
                    $page->getTableRecord((string) $order->getKey()),
                    "{$label}: order #{$order->getKey()} is outside the scope and must not resolve",
                );
            }
        }
    }

    public function test_the_detail_action_never_shows_an_order_outside_the_users_scope(): void
    {
        $w = $this->world();

        $secrets = [
            'main' => 'Secret Main Latte',
            'sibling' => 'Secret Sibling Mocha',
            'foreign' => 'Secret Foreign Espresso',
        ];

        OrderItem::factory()->for($w['mainOrder'])->create(['product_name' => $secrets['main']]);
        OrderItem::factory()->for($w['siblingOrder'])->create(['product_name' => $secrets['sibling']]);
        OrderItem::factory()->for($w['foreignOrder'])->create(['product_name' => $secrets['foreign']]);

        $main = [$w['mainOrder'], $secrets['main']];
        $sibling = [$w['siblingOrder'], $secrets['sibling']];
        $foreign = [$w['foreignOrder'], $secrets['foreign']];

        $cases = [
            'owner' => [$w['owner'], [$main, $sibling], [$foreign]],
            'business admin' => [$w['admin'], [$main, $sibling], [$foreign]],
            'cashier of the main outlet' => [$w['cashierMain'], [$main], [$sibling, $foreign]],
            'cashier of the sibling outlet' => [$w['cashierSibling'], [$sibling], [$main, $foreign]],
            'owner of the other business' => [$w['foreignOwner'], [$foreign], [$main, $sibling]],
            'admin of the other business' => [$w['foreignAdmin'], [$foreign], [$main, $sibling]],
        ];

        foreach ($cases as $label => [$user, $inside, $outside]) {
            foreach ($inside as [$order, $secret]) {
                $page = $this->historyAs($user);
                $page->mountTableAction('viewDetails', $order);
                $this->assertCount(1, $page->instance()->getMountedActions(), $label.': inside, action mounted');
                $this->assertSame(
                    (string) $order->getKey(),
                    (string) $page->instance()->getMountedAction()->getRecord()->getKey(),
                    $label.': inside, mounted record',
                );
                $page->assertMountedActionModalSee($secret);
            }

            foreach ($outside as [$order, $secret]) {
                $page = $this->historyAs($user);

                try {
                    $page->mountTableAction('viewDetails', $order);
                } catch (Throwable) {
                    // Refusing to open a record outside the scope is acceptable.
                }

                $this->assertCount(0, $page->instance()->getMountedActions(), $label.': outside, no action mounted');

                $leaked = true;

                try {
                    $page->assertMountedActionModalSee($secret);
                } catch (AssertionFailedError) {
                    $leaked = false;
                }

                $this->assertFalse($leaked, $label.': outside, modal shows nothing');
            }
        }
    }

    public function test_page_access_follows_the_role(): void
    {
        $w = $this->world();

        $expected = [
            'owner' => [$w['owner'], true],
            'owner without a business' => [User::factory()->create(), true],
            'business admin' => [$w['admin'], true],
            'cashier' => [$w['cashierMain'], true],
            'kitchen' => [$w['kitchen'], false],
        ];

        foreach ($expected as $label => [$user, $allowed]) {
            $this->actingAs($user);

            $this->assertSame($allowed, TransactionHistory::canAccess(), $label);
        }
    }

    public function test_the_history_url_follows_the_role(): void
    {
        $w = $this->world();

        $expected = [
            'owner' => [$w['owner'], 200],
            'owner without a business' => [User::factory()->create(), 200],
            'business admin' => [$w['admin'], 200],
            'cashier' => [$w['cashierMain'], 200],
            'kitchen' => [$w['kitchen'], 403],
        ];

        foreach ($expected as $label => [$user, $status]) {
            $this->flushSession();

            $this->assertSame(
                $status,
                $this->actingAs($user)->get('/admin/transaction-history')->getStatusCode(),
                $label,
            );
        }
    }
}
