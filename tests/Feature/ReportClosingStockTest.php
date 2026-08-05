<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportClosingStockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Mill $kbb;
    private Mill $kahang;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function (Blueprint $table) {
                $table->string('operation_status')->default('Operasi')->after('officer_id');
            });
        }

        $role = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);
        $this->kbb = $this->createMill('Kilang Sawit Bukit Bujang', 'BBJ');
        $this->kahang = $this->createMill('Kilang Sawit PPNJ Kahang', 'KHG');
        $this->admin = User::create([
            'name' => 'Admin Laporan',
            'email' => 'admin-laporan@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function test_full_month_uses_last_record_stock_in_date_and_id_order(): void
    {
        $first = $this->createOperation($this->kbb, '2026-07-01', [
            'stok_cpo_yesterday' => 10.0,
            'stok_pk_yesterday' => 5.0,
            'stok_cpo' => 100.0,
            'stok_pk' => 20.0,
            'bts_diproses' => 300.0,
        ]);
        $penultimate = $this->createOperation($this->kbb, '2026-07-31', [
            'shift' => 'Shift 1',
            'stok_cpo_yesterday' => 100.0,
            'stok_pk_yesterday' => 20.0,
            'stok_cpo' => 400.0,
            'stok_pk' => 80.0,
            'bts_diproses' => 400.0,
        ]);
        $last = $this->createOperation($this->kbb, '2026-07-31', [
            'shift' => 'Shift 2',
            'stok_cpo_yesterday' => 400.0,
            'stok_pk_yesterday' => 80.0,
            'stok_cpo' => 425.0,
            'stok_pk' => 85.0,
            'bts_diproses' => 500.0,
        ]);

        $response = $this->actingAs($this->admin)->get(route('laporan.index', [
            'mill_id' => $this->kbb->id,
            'tarikh_mula' => '2026-07-01',
            'tarikh_akhir' => '2026-07-31',
        ]));

        $this->assertSame(
            [$first->id, $penultimate->id, $last->id],
            $response->viewData('records')->pluck('id')->all()
        );

        $response->assertOk()
            ->assertViewHas('closingCpoStock', 425.0)
            ->assertViewHas('closingPkStock', 85.0)
            ->assertViewHas('closingStockLabel', 'Stok Akhir Bulan')
            ->assertSee('Stok Akhir Bulan')
            ->assertSee('425.00')
            ->assertDontSee('510.00');

        $this->assertSame(1200.0, (float) $response->viewData('records')->sum('bts_diproses'));
    }

    public function test_combined_report_sums_last_stock_record_for_each_mill(): void
    {
        $this->createOperation($this->kbb, '2026-07-10', ['stok_cpo' => 900.0, 'stok_pk' => 90.0]);
        $this->createOperation($this->kbb, '2026-07-31', ['stok_cpo' => 425.0, 'stok_pk' => 85.0]);
        $this->createOperation($this->kahang, '2026-07-15', ['stok_cpo' => 800.0, 'stok_pk' => 70.0]);
        $this->createOperation($this->kahang, '2026-07-30', ['stok_cpo' => 275.0, 'stok_pk' => 55.0]);

        $response = $this->actingAs($this->admin)->get(route('laporan.index', [
            'tarikh_mula' => '2026-07-01',
            'tarikh_akhir' => '2026-07-31',
        ]));

        $response->assertOk()
            ->assertViewHas('closingCpoStock', 700.0)
            ->assertViewHas('closingPkStock', 140.0)
            ->assertViewHas('closingStockLabel', 'Stok Akhir Bulan');
    }

    public function test_partial_period_print_report_uses_closing_stock_and_period_label(): void
    {
        $this->createOperation($this->kbb, '2026-07-10', ['stok_cpo' => 120.0, 'stok_pk' => 30.0]);
        $this->createOperation($this->kbb, '2026-07-20', ['stok_cpo' => 150.0, 'stok_pk' => 40.0]);

        $response = $this->actingAs($this->admin)->get(route('laporan.export.pdf', [
            'mill_id' => $this->kbb->id,
            'tarikh_mula' => '2026-07-10',
            'tarikh_akhir' => '2026-07-20',
        ]));

        $response->assertOk()
            ->assertViewIs('laporan.print')
            ->assertViewHas('closingCpoStock', 150.0)
            ->assertViewHas('closingPkStock', 40.0)
            ->assertViewHas('closingStockLabel', 'Stok Akhir Tempoh')
            ->assertSee('Stok Akhir Tempoh')
            ->assertSee('150.00');
    }

    public function test_empty_report_returns_zero_closing_stock_without_error(): void
    {
        $parameters = [
            'tarikh_mula' => '2026-07-01',
            'tarikh_akhir' => '2026-07-31',
        ];

        $this->actingAs($this->admin)
            ->get(route('laporan.index', $parameters))
            ->assertOk()
            ->assertViewHas('closingCpoStock', 0.0)
            ->assertViewHas('closingPkStock', 0.0)
            ->assertViewHas('closingStockLabel', 'Stok Akhir Bulan');

        $this->actingAs($this->admin)
            ->get(route('laporan.export.pdf', $parameters))
            ->assertOk()
            ->assertViewHas('closingCpoStock', 0.0)
            ->assertViewHas('closingPkStock', 0.0);
    }

    private function createMill(string $name, string $code): Mill
    {
        return Mill::create([
            'name' => $name,
            'code' => $code,
            'location' => 'Johor',
            'is_active' => true,
        ]);
    }

    private function createOperation(Mill $mill, string $date, array $overrides = []): DailyOperation
    {
        return DailyOperation::create(array_merge([
            'tarikh' => $date,
            'mill_id' => $mill->id,
            'shift' => 'Harian',
            'officer_id' => $this->admin->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => 0.0,
            'bts_diproses' => 0.0,
            'baki_bts_semalam' => 0.0,
            'baki_bts_selepas_diproses' => 0.0,
            'jam_operasi' => 0.0,
            'downtime_jam' => 0.0,
            'pengeluaran_cpo' => 0.0,
            'pengeluaran_pk' => 0.0,
            'pk_kcp_to_hopper' => 0.0,
            'produksi_cpo' => 0.0,
            'produksi_pk' => 0.0,
            'stok_cpo_yesterday' => 0.0,
            'stok_pk_yesterday' => 0.0,
            'stok_cpo' => 0.0,
            'stok_pk' => 0.0,
            'oer' => 0.0,
            'ker' => 0.0,
            'ffa' => 0.0,
            'moisture' => 0.0,
            'dirt' => 0.0,
            'throughput' => 0.0,
            'utilisation_rate' => 0.0,
            'status' => 'submitted',
        ], $overrides));
    }
}
