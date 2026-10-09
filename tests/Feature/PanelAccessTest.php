<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirectContains('/admin/login');
    }

    public function test_an_authenticated_user_can_open_the_panel_outside_the_local_environment(): void
    {
        // Filament only skips the FilamentUser check when app.env is "local".
        config(['app.env' => 'production']);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk();
    }

    public function test_user_implements_the_filament_contract_and_may_access_the_admin_panel(): void
    {
        $user = User::factory()->make();

        $this->assertInstanceOf(FilamentUser::class, $user);
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
    }
}
