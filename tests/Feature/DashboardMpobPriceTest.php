<?php

namespace Tests\Feature;

use App\Models\Mill;
use App\Models\MpobPriceHistory;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MpobPriceService;
use Carbon\Carbon;
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

    public function test_dashboard_opens_without_mpob_history_records(): void
    {
        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([10.5, 9.25, 8.75]));
        });

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('mpobSparkline-cpo', false)
            ->assertSee('mpobSparkline-pk', false)
            ->assertSee('mpobSparkline-cpko', false)
            ->assertSee('Trend belum tersedia.', false);
    }

    public function test_dashboard_supports_less_than_30_history_records(): void
    {
        $this->seedHistory('cpo', '2026-07-01', 5, 4500);

        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([4600, 3900, 8100]));
        });

        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('"cpo":{"labels":["2026-07-01","2026-07-02","2026-07-03","2026-07-04","2026-07-05"]', false);
        $response->assertSee('"values":[4500,4501,4502,4503,4504]', false);
    }

    public function test_dashboard_uses_maximum_30_records_and_sorted_ascending(): void
    {
        $this->seedHistory('pk', '2026-06-01', 35, 3000);

        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([4600, 3900, 8100]));
        });

        $response = $this->actingAs($this->admin)
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('"pk":{"labels":["2026-06-06"', false);
        $response->assertSee('"2026-07-05"]', false);
        $response->assertDontSee('"2026-06-01"', false);
    }

    public function test_pengurusan_role_can_access_dashboard(): void
    {
        $role = Role::create(['name' => Role::PENGURUSAN, 'label' => 'Pengurusan']);
        $user = User::create([
            'name' => 'Pengurusan Tester',
            'email' => 'pengurusan-dashboard@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => null,
            'is_active' => true,
        ]);

        $this->mock(MpobPriceService::class, function ($mock) {
            $mock->shouldReceive('getForDashboard')->once()->andReturn($this->payload([10.5, 9.25, 8.75]));
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Harga Harian MPOB', false);
    }

    private function seedHistory(string $category, string $startDate, int $days, float $startingPrice): void
    {
        $date = Carbon::parse($startDate);

        for ($i = 0; $i < $days; $i++) {
            MpobPriceHistory::create([
                'category' => $category,
                'trade_date' => $date->copy()->addDays($i)->toDateString(),
                'price' => $startingPrice + $i,
                'source_checked_at' => now(),
            ]);
        }
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