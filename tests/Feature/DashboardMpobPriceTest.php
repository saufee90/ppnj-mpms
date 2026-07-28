<?php

namespace Tests\Feature;

use App\Models\Mill;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MpobPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardMpobPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::forgetCache();

        $adminRole = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);

        Mill::create([
            'name' => 'Kilang Sawit Bukit Bujang',
            'code' => 'BBJ',
            'location' => 'Segamat',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin Tester',
            'email' => 'dashboard-mpob@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $adminRole->id,
            'mill_id' => null,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dashboard_shows_mpob_prices_when_service_returns_data(): void
    {
        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([10.5, 9.25, 8.75]));
        });

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Harga Harian MPOB', false)
            ->assertSee('CPO', false)
            ->assertSee('PK', false)
            ->assertSee('CPKO', false)
            ->assertSee('RM 10.50', false)
            ->assertSee('RM 9.25', false)
            ->assertSee('RM 8.75', false)
            ->assertSee('27/07/2026 10:30 AM', false);
    }

    public function test_dashboard_still_returns_ok_when_all_mpob_prices_are_null(): void
    {
        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([null, null, null], false));
        });

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Data harga belum tersedia', false);
    }

    public function test_dashboard_still_returns_ok_when_mpob_source_is_unavailable(): void
    {
        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([11.11, 10.22, 9.33], false, false));
        });

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sumber MPOB tidak dapat dicapai. Memaparkan data terakhir yang disimpan.', false)
            ->assertSee('RM 11.11', false)
            ->assertSee('RM 10.22', false)
            ->assertSee('RM 9.33', false);
    }

    private function payload(array $prices, bool $sourceAvailable = true, bool $includeTimestamps = true): array
    {
        return [
            'products' => [
                'cpo' => ['name' => 'Crude Palm Oil (CPO)', 'price' => $prices[0], 'price_date' => '2026-07-27'],
                'pk' => ['name' => 'Palm Kernel (PK)', 'price' => $prices[1], 'price_date' => '2026-07-27'],
                'cpko' => ['name' => 'Crude Palm Kernel Oil (CPKO)', 'price' => $prices[2], 'price_date' => '2026-07-27'],
            ],
            'mpob_last_update' => '27/07/2026 10:30 AM',
            'source_url' => MpobPriceService::SOURCE_URL,
            'refreshed_at' => $includeTimestamps ? '2026-07-27T10:35:00Z' : null,
            'checked_at' => $includeTimestamps ? '2026-07-27T10:40:00Z' : null,
            'source_available' => $sourceAvailable,
        ];
    }
}