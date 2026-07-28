<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyOperationRecalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DailyOperationRecalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyOperationRecalculationService $service;
    private Mill $mill;
    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function ($table) {
                $table->string('operation_status')->default('Operasi')->after('officer_id');
            });
        }

        $this->service = app(DailyOperationRecalculationService::class);

        $role = Role::create([
            'name' => 'pegawai_kilang',
            'label' => 'Pegawai Kilang',
        ]);

        $this->mill = Mill::create([
            'name' => 'Kilang Sawit Bukit Bujang',
            'code' => 'BBJ',
            'location' => 'Segamat, Johor',
            'is_active' => true,
        ]);

        $this->officer = User::create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => $this->mill->id,
            'is_active' => true,
        ]);
    }

    public function test_historical_amendment_recalculates_following_days_for_same_mill(): void
    {
        $day25 = $this->createOperation([
            'tarikh' => '2026-07-25',
            'operation_status' => 'Operasi',
            'baki_bts_semalam' => 150.0,
            'bts_diterima' => 40.0,
            'bts_diproses' => 17.0,
            'baki_bts_selepas_diproses' => 173.0,
            'stok_cpo_yesterday' => 280.0,
            'stok_cpo' => 292.4,
            'pengeluaran_cpo' => 20.0,
            'stok_pk_yesterday' => 100.0,
            'stok_pk' => 102.0,
            'pengeluaran_pk' => 5.0,
            'jam_operasi' => 10.0,
        ]);

        $this->createOperation([
            'tarikh' => '2026-07-26',
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'baki_bts_semalam' => 173.0,
            'bts_diterima' => 0.0,
            'bts_diproses' => 0.0,
            'baki_bts_selepas_diproses' => 173.0,
            'stok_cpo_yesterday' => 292.4,
            'stok_cpo' => 292.4,
            'stok_pk_yesterday' => 102.0,
            'stok_pk' => 102.0,
            'pengeluaran_cpo' => 0.0,
            'pengeluaran_pk' => 0.0,
            'jam_operasi' => 0.0,
            'downtime_jam' => 0.0,
        ]);

        $this->createOperation([
            'tarikh' => '2026-07-27',
            'operation_status' => 'Operasi',
            'baki_bts_semalam' => 173.0,
            'bts_diterima' => 10.0,
            'bts_diproses' => 10.0,
            'baki_bts_selepas_diproses' => 173.0,
            'stok_cpo_yesterday' => 292.4,
            'stok_cpo' => 300.0,
            'stok_pk_yesterday' => 102.0,
            'stok_pk' => 104.0,
            'pengeluaran_cpo' => 12.0,
            'pengeluaran_pk' => 2.0,
            'jam_operasi' => 8.0,
            'downtime_jam' => 1.0,
        ]);

        // Simulate amendment on historical day.
        $day25->update([
            'bts_diterima' => 56.0,
            'stok_cpo' => 296.9,
        ]);

        $this->service->recalculateFromDate((int) $this->mill->id, Carbon::parse('2026-07-25'));

        $day25->refresh();
        $day26 = DailyOperation::whereDate('tarikh', '2026-07-26')->where('mill_id', $this->mill->id)->firstOrFail();
        $day27 = DailyOperation::whereDate('tarikh', '2026-07-27')->where('mill_id', $this->mill->id)->firstOrFail();

        $this->assertSame(189.0, (float) $day25->baki_bts_selepas_diproses);
        $this->assertSame(296.9, (float) $day25->stok_cpo);

        $this->assertSame(189.0, (float) $day26->baki_bts_semalam);
        $this->assertSame(189.0, (float) $day26->baki_bts_selepas_diproses);
        $this->assertSame(296.9, (float) $day26->stok_cpo_yesterday);
        $this->assertSame(296.9, (float) $day26->stok_cpo);
        $this->assertSame(0.0, (float) $day26->produksi_cpo);

        $this->assertSame(189.0, (float) $day27->baki_bts_semalam);
        $this->assertSame(296.9, (float) $day27->stok_cpo_yesterday);
    }

    public function test_recalculation_is_rolled_back_when_one_row_fails(): void
    {
        $day25 = $this->createOperation([
            'tarikh' => '2026-07-25',
            'operation_status' => 'Operasi',
            'baki_bts_semalam' => 10.0,
            'bts_diterima' => 10.0,
            'bts_diproses' => 5.0,
            'baki_bts_selepas_diproses' => 15.0,
            'stok_cpo_yesterday' => 5.0,
            'stok_cpo' => 10.0,
            'pengeluaran_cpo' => 3.0,
            'stok_pk_yesterday' => 5.0,
            'stok_pk' => 10.0,
            'pengeluaran_pk' => 3.0,
        ]);

        $day26 = $this->createOperation([
            'tarikh' => '2026-07-26',
            'operation_status' => 'Operasi',
            'baki_bts_semalam' => 15.0,
            'bts_diterima' => 0.0,
            'bts_diproses' => 1.0,
            'baki_bts_selepas_diproses' => 14.0,
            'stok_cpo_yesterday' => 10.0,
            'stok_cpo' => 1.0,
            'pengeluaran_cpo' => 0.0,
            'stok_pk_yesterday' => 10.0,
            'stok_pk' => 1.0,
            'pengeluaran_pk' => 0.0,
        ]);

        $originalDay25Baki = (float) $day25->baki_bts_selepas_diproses;
        $originalDay26BakiSemalam = (float) $day26->baki_bts_semalam;

        $day25->update(['bts_diterima' => 20.0]);

        try {
            $this->service->recalculateFromDate((int) $this->mill->id, Carbon::parse('2026-07-25'));
            $this->fail('Expected recalculation to fail due to negative production.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('produksi_cpo', $exception->errors());
        }

        $day25->refresh();
        $day26->refresh();

        $this->assertSame($originalDay25Baki, (float) $day25->baki_bts_selepas_diproses);
        $this->assertSame($originalDay26BakiSemalam, (float) $day26->baki_bts_semalam);
    }

    private function createOperation(array $overrides): DailyOperation
    {
        $defaults = [
            'mill_id' => $this->mill->id,
            'officer_id' => $this->officer->id,
            'shift' => 'Harian',
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
            'stok_cpo' => 0.0,
            'stok_pk' => 0.0,
            'stok_cpo_yesterday' => 0.0,
            'stok_pk_yesterday' => 0.0,
            'oer' => 0.0,
            'ker' => 0.0,
            'ffa' => 0.0,
            'moisture' => 0.0,
            'dirt' => 0.0,
            'throughput' => 0.0,
            'utilisation_rate' => 0.0,
            'status' => 'submitted',
        ];

        return DailyOperation::create(array_merge($defaults, $overrides));
    }
}
