<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Business;
use App\Models\Outlet;
use App\Models\User;
use App\Services\StaffService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'demo-password-123';

    /** Pages and resources that only owner accounts may open. */
    private const OWNER_AREAS = [
        '/admin/businesses',
        '/admin/businesses/create',
        '/admin/outlets',
        '/admin/outlets/create',
        '/admin/categories',
        '/admin/categories/create',
        '/admin/products',
        '/admin/products/create',
        '/admin/product-variants',
        '/admin/product-variants/create',
        '/admin/staff',
        '/admin/staff/create',
        '/admin/stock-management',
        '/admin/transaction-history',
        '/admin/business-insight',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        // Filament only skips the FilamentUser check when app.env is "local".
        config(['app.env' => 'production']);
    }

    /**
     * @return array{owner: User, business: Business, outlet: Outlet, admin: User, cashier: User, kitchen: User}
     */
    private function world(): array
    {
        $owner = User::factory()->create(['password' => self::PASSWORD]);
        $business = Business::factory()->for($owner)->create();
        $outlet = Outlet::factory()->for($business)->create();
        $staff = app(StaffService::class);

        $create = fn (string $email, StaffRole $role, ?Outlet $assigned): User => $staff->create($owner, [
            'name' => ucfirst($role->value),
            'email' => $email,
            'password' => self::PASSWORD,
            'business_id' => $business->id,
            'role' => $role->value,
            'outlet_id' => $assigned?->id,
        ]);

        return [
            'owner' => $owner,
            'business' => $business,
            'outlet' => $outlet,
            'admin' => $create('admin@example.test', StaffRole::BusinessAdmin, null),
            'cashier' => $create('cashier@example.test', StaffRole::Cashier, $outlet),
            'kitchen' => $create('kitchen@example.test', StaffRole::Kitchen, $outlet),
        ];
    }

    private function assertStatus(User $user, string $path, int $expected): void
    {
        $status = $this->actingAs($user)->get($path)->getStatusCode();

        $this->assertSame(
            $expected,
            $status,
            ($user->role ?? 'owner')." on {$path}: expected {$expected}, got {$status}",
        );
    }

    public function test_owner_reaches_every_owner_area_and_the_cashier(): void
    {
        $world = $this->world();

        foreach ([...self::OWNER_AREAS, '/admin/cashier', '/admin'] as $path) {
            $this->assertStatus($world['owner'], $path, 200);
        }
    }

    public function test_business_admin_is_denied_owner_only_areas(): void
    {
        $world = $this->world();

        $denied = [
            '/admin/businesses',
            '/admin/businesses/create',
            '/admin/staff',
            '/admin/staff/create',
            '/admin/business-insight',
            '/admin/cashier',
        ];

        foreach ($denied as $path) {
            $this->assertStatus($world['admin'], $path, 403);
        }

        $this->assertStatus($world['admin'], '/admin', 200);
    }

    public function test_cashier_only_reaches_the_cashier(): void
    {
        $world = $this->world();

        // Transaction history is left out on purpose: viewing orders is a cashier
        // ability that is still to be opened up on that page.
        $denied = array_diff(self::OWNER_AREAS, ['/admin/transaction-history']);

        foreach ($denied as $path) {
            $this->assertStatus($world['cashier'], $path, 403);
        }

        $this->assertStatus($world['cashier'], '/admin/cashier', 200);
        $this->assertStatus($world['cashier'], '/admin', 200);
    }

    public function test_kitchen_is_denied_everything_except_the_dashboard(): void
    {
        $world = $this->world();

        foreach ([...self::OWNER_AREAS, '/admin/cashier'] as $path) {
            $this->assertStatus($world['kitchen'], $path, 403);
        }

        $this->assertStatus($world['kitchen'], '/admin', 200);
    }

    public function test_staff_cannot_open_records_of_another_business_by_url(): void
    {
        $world = $this->world();

        $otherOwner = User::factory()->create();
        $otherBusiness = Business::factory()->for($otherOwner)->create();
        $otherOutlet = Outlet::factory()->for($otherBusiness)->create();

        $url = '/admin/outlets/'.$otherOutlet->getKey().'/edit';

        foreach (['admin', 'cashier', 'kitchen', 'owner'] as $who) {
            $this->flushSession();
            $status = $this->actingAs($world[$who])->get($url)->getStatusCode();

            $this->assertContains($status, [403, 404], "{$who} must not open another business's outlet (got {$status})");
        }
    }

    public function test_panel_access_follows_the_assignment_rules(): void
    {
        $panel = Filament::getPanel('admin');
        $make = fn (?string $role, ?int $business, ?int $outlet): User => User::factory()->make()->forceFill([
            'role' => $role,
            'business_id' => $business,
            'outlet_id' => $outlet,
        ]);

        $allowed = [
            'owner' => [null, null, null],
            'business admin' => [StaffRole::BusinessAdmin->value, 1, null],
            'cashier' => [StaffRole::Cashier->value, 1, 1],
            'kitchen' => [StaffRole::Kitchen->value, 1, 1],
        ];

        foreach ($allowed as $label => $args) {
            $this->assertTrue($make(...$args)->canAccessPanel($panel), $label);
        }

        $denied = [
            'unknown role' => ['owner', 1, null],
            'role without business' => [StaffRole::Cashier->value, null, null],
            'business without role' => [null, 1, null],
            'outlet without role' => [null, null, 1],
            'cashier without outlet' => [StaffRole::Cashier->value, 1, null],
            'kitchen without outlet' => [StaffRole::Kitchen->value, 1, null],
            'business admin with outlet' => [StaffRole::BusinessAdmin->value, 1, 1],
        ];

        foreach ($denied as $label => $args) {
            $this->assertFalse($make(...$args)->canAccessPanel($panel), $label);
        }
    }

    public function test_every_role_can_log_in_and_log_out_again(): void
    {
        $world = $this->world();

        foreach (['owner', 'admin', 'cashier', 'kitchen'] as $who) {
            $user = $world[$who];

            Livewire::test(Login::class)
                ->fillForm(['email' => $user->email, 'password' => self::PASSWORD])
                ->call('authenticate')
                ->assertHasNoFormErrors();

            $this->assertTrue(
                auth()->check() && (int) auth()->id() === (int) $user->getKey(),
                "{$who} should be authenticated after logging in",
            );

            auth()->logout();

            $this->assertGuest();
        }
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $world = $this->world();

        Livewire::test(Login::class)
            ->fillForm(['email' => $world['cashier']->email, 'password' => 'not-the-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }
}
