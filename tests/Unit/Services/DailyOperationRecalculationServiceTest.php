<?php

namespace Tests\Unit\Services;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use App\Services\DailyOperationRecalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            'email' => 'unit-tester@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => $this->mill->id,
            'is_active' => true,
        ]);
    }

    public function test_prepare_for_persistence_adds_received_bts_for_receive_only_day(): void
    {
        $this->createOperation([
            'tarikh' => '2026-07-11',
            'baki_bts_selepas_diproses' => 168.0,
            'stok_cpo' => 100.0,
            'stok_pk' => 50.0,
        ]);

        $prepared = $this->service->prepareForPersistence($this->persistenceData([
            'tarikh' => '2026-07-12',
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'bts_diterima' => 157.8,
            'bts_diproses' => 20.0,
            'jam_operasi' => 8.0,
            'downtime_jam' => 2.0,
        ]));

        $this->assertSame(168.0, (float) $prepared['baki_bts_semalam']);
        $this->assertSame(325.8, (float) $prepared['baki_bts_selepas_diproses']);
        $this->assertSame(0.0, (float) $prepared['bts_diproses']);
        $this->assertSame(0.0, (float) $prepared['jam_operasi']);
        $this->assertSame(0.0, (float) $prepared['downtime_jam']);
    }

    public function test_recalculation_carries_received_bts_across_consecutive_receive_only_days(): void
    {
        $this->createOperation([
            'tarikh' => '2026-07-11',
            'baki_bts_selepas_diproses' => 168.0,
            'stok_cpo' => 100.0,
            'stok_pk' => 50.0,
        ]);

        $day12 = $this->createOperation([
            'tarikh' => '2026-07-12',
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'baki_bts_semalam' => 168.0,
            'bts_diterima' => 157.8,
            'baki_bts_selepas_diproses' => 168.0,
        ]);
        $day13 = $this->createOperation([
            'tarikh' => '2026-07-13',
            'operation_status' => 'Tidak Operasi (Terima Buah Sahaja)',
            'baki_bts_semalam' => 168.0,
            'bts_diterima' => 183.99,
            'baki_bts_selepas_diproses' => 168.0,
        ]);
        $day14 = $this->createOperation([
            'tarikh' => '2026-07-14',
            'baki_bts_semalam' => 168.0,
            'bts_diterima' => 482.5,
            'bts_diproses' => 774.29,
            'baki_bts_selepas_diproses' => 0.0,
        ]);

        $this->service->recalculateFromDate((int) $this->mill->id, Carbon::parse('2026-07-12'));

        $day12->refresh();
        $day13->refresh();
        $day14->refresh();

        $this->assertSame(325.8, (float) $day12->baki_bts_selepas_diproses);
        $this->assertSame(325.8, (float) $day13->baki_bts_semalam);
        $this->assertSame(509.79, (float) $day13->baki_bts_selepas_diproses);
        $this->assertSame(509.79, (float) $day14->baki_bts_semalam);
        $this->assertSame(218.0, (float) $day14->baki_bts_selepas_diproses);
    }

    public function test_prepare_for_persistence_keeps_hopper_in_pk_production_once(): void
    {
        $this->createOperation([
            'tarikh' => '2026-07-01',
            'baki_bts_selepas_diproses' => 225.09,
            'stok_cpo' => 100.0,
            'stok_pk' => 247.09,
        ]);

        $prepared = $this->service->prepareForPersistence($this->persistenceData([
            'tarikh' => '2026-07-02',
            'bts_diterima' => 467.95,
            'bts_diproses' => 533.04,
            'stok_pk' => 251.47,
            'pk_kcp_to_hopper' => 20.96,
        ]));

        $this->assertSame(160.0, (float) $prepared['baki_bts_selepas_diproses']);
        $this->assertSame(25.34, (float) $prepared['produksi_pk']);
    }

    private function persistenceData(array $overrides): array
    {
        return array_merge([
            'mill_id' => $this->mill->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => 0.0,
            'bts_diproses' => 0.0,
            'jam_operasi' => 0.0,
            'downtime_jam' => 0.0,
            'pengeluaran_cpo' => 0.0,
            'pengeluaran_pk' => 0.0,
            'pk_kcp_to_hopper' => 0.0,
            'stok_cpo_yesterday' => 0.0,
            'stok_pk_yesterday' => 0.0,
            'stok_cpo' => 100.0,
            'stok_pk' => 50.0,
        ], $overrides);
    }

    private function createOperation(array $overrides): DailyOperation
    {
        return DailyOperation::create(array_merge([
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
            'stok_cpo' => 100.0,
            'stok_pk' => 50.0,
            'stok_cpo_yesterday' => 100.0,
            'stok_pk_yesterday' => 50.0,
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