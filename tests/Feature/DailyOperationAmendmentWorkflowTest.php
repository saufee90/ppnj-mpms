<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyOperationAmendmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Mill $bbj;
    private Mill $khg;
    private User $admin;
    private User $pegawaiBbj;
    private User $pegawaiKhg;
    private User $pengurusan;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function ($table) {
                $table->string('operation_status')->default('Operasi')->after('officer_id');
            });
        }

        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
        Carbon::setTestNow();

        $adminRole = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);
        $pegawaiRole = Role::create(['name' => Role::PEGAWAI_KILANG, 'label' => 'Pegawai Kilang']);
        $pengurusanRole = Role::create(['name' => Role::PENGURUSAN, 'label' => 'Pengurusan']);

        $this->bbj = Mill::create([
            'name' => 'Kilang Sawit Bukit Bujang',
            'code' => 'BBJ',
            'location' => 'Segamat',
            'is_active' => true,
        ]);

        $this->khg = Mill::create([
            'name' => 'Kilang Sawit PPNJ Kahang',
            'code' => 'KHG',
            'location' => 'Kluang',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin Tester',
            'email' => 'admin@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $adminRole->id,
            'mill_id' => null,
            'is_active' => true,
        ]);

        $this->pegawaiBbj = User::create([
            'name' => 'Pegawai BBJ',
            'email' => 'pegawai-bbj@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $pegawaiRole->id,
            'mill_id' => $this->bbj->id,
            'is_active' => true,
        ]);

        $this->pegawaiKhg = User::create([
            'name' => 'Pegawai KHG',
            'email' => 'pegawai-khg@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $pegawaiRole->id,
            'mill_id' => $this->khg->id,
            'is_active' => true,
        ]);

        $this->pengurusan = User::create([
            'name' => 'Pengurusan Tester',
            'email' => 'pengurusan@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $pengurusanRole->id,
            'mill_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_create_and_edit_forms_include_confirmation_modal_markup_and_amendment_reason_field(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id, [
            'bts_diterima' => 12,
            'bts_diproses' => 10,
        ]);

        $this->actingAs($this->pegawaiBbj)
            ->get(route('data-harian.create'))
            ->assertOk()
            ->assertSee('Pengesahan Rekod Harian', false)
            ->assertSee('Sahkan dan Simpan', false)
            ->assertSee('Saya mengesahkan bahawa semua data telah disemak dan adalah tepat.', false);

        $this->actingAs($this->pegawaiBbj)
            ->get(route('data-harian.edit', $operation))
            ->assertOk()
            ->assertSee('Pengesahan Rekod Harian', false)
            ->assertSee('name="amendment_reason"', false)
            ->assertSee('Sebab Pindaan', false);
    }

    public function test_pegawai_kilang_can_edit_within_three_day_window_but_denied_on_day_four(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id);

        foreach (['2026-07-25', '2026-07-26', '2026-07-27', '2026-07-28'] as $date) {
            Carbon::setTestNow(Carbon::parse($date . ' 10:00:00', 'Asia/Kuala_Lumpur'));

            $this->actingAs($this->pegawaiBbj)
                ->get(route('data-harian.edit', $operation))
                ->assertOk();
        }

        Carbon::setTestNow(Carbon::parse('2026-07-29 09:00:00', 'Asia/Kuala_Lumpur'));

        $this->actingAs($this->pegawaiBbj)
            ->get(route('data-harian.edit', $operation))
            ->assertRedirect(route('rekod-harian.index'))
            ->assertSessionHas('error', 'Tempoh pindaan rekod ini telah tamat. Sila hubungi Admin untuk pembetulan lanjut.');
    }

    public function test_admin_can_edit_after_three_day_window(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id);
        Carbon::setTestNow(Carbon::parse('2026-07-29 09:00:00', 'Asia/Kuala_Lumpur'));

        $this->actingAs($this->admin)
            ->get(route('data-harian.edit', $operation))
            ->assertOk();
    }

    public function test_pegawai_cannot_edit_another_mill_record(): void
    {
        $operation = $this->createOperation($this->khg->id, '2026-07-25', $this->pegawaiKhg->id);

        $this->actingAs($this->pegawaiBbj)
            ->get(route('data-harian.edit', $operation))
            ->assertForbidden();
    }

    public function test_pengurusan_remains_unable_to_edit_daily_operation(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id);

        $this->actingAs($this->pengurusan)
            ->get(route('data-harian.edit', $operation))
            ->assertForbidden();
    }

    public function test_update_requires_amendment_reason(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id);
        Carbon::setTestNow(Carbon::parse('2026-07-26 10:00:00', 'Asia/Kuala_Lumpur'));

        $payload = $this->validPayload($operation, [
            'bts_diterima' => 14,
            'amendment_reason' => '',
        ]);

        $this->actingAs($this->pegawaiBbj)
            ->from(route('data-harian.edit', $operation))
            ->put(route('data-harian.update', $operation), $payload)
            ->assertRedirect(route('data-harian.edit', $operation))
            ->assertSessionHasErrors('amendment_reason');
    }

    public function test_direct_update_request_after_expiry_is_rejected(): void
    {
        $operation = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id);
        Carbon::setTestNow(Carbon::parse('2026-07-29 11:00:00', 'Asia/Kuala_Lumpur'));

        $payload = $this->validPayload($operation, [
            'bts_diterima' => 16,
            'amendment_reason' => 'Pembetulan angka sebenar dari Daily Figure.',
        ]);

        $this->actingAs($this->pegawaiBbj)
            ->put(route('data-harian.update', $operation), $payload)
            ->assertRedirect(route('rekod-harian.index'))
            ->assertSessionHas('error', 'Tempoh pindaan rekod ini telah tamat. Sila hubungi Admin untuk pembetulan lanjut.');
    }

    public function test_successful_amendment_updates_metadata_triggers_recalculation_and_logs_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', 'Asia/Kuala_Lumpur'));

        $day25 = $this->createOperation($this->bbj->id, '2026-07-25', $this->pegawaiBbj->id, [
            'bts_diterima' => 10,
            'bts_diproses' => 5,
            'baki_bts_semalam' => 0,
            'baki_bts_selepas_diproses' => 5,
            'stok_cpo_yesterday' => 100,
            'stok_cpo' => 100,
            'stok_pk_yesterday' => 60,
            'stok_pk' => 60,
        ]);

        $day26 = $this->createOperation($this->bbj->id, '2026-07-26', $this->pegawaiBbj->id, [
            'bts_diterima' => 1,
            'bts_diproses' => 1,
            'baki_bts_semalam' => 5,
            'baki_bts_selepas_diproses' => 5,
            'stok_cpo_yesterday' => 100,
            'stok_cpo' => 100,
            'stok_pk_yesterday' => 60,
            'stok_pk' => 60,
        ]);

        $payload = $this->validPayload($day25, [
            'bts_diterima' => 20,
            'amendment_reason' => 'Pembetulan berdasarkan Daily Figure kilang.',
        ]);

        $this->actingAs($this->pegawaiBbj)
            ->put(route('data-harian.update', $day25), $payload)
            ->assertRedirect(route('rekod-harian.index'))
            ->assertSessionHas('success');

        $day25->refresh();
        $day26->refresh();

        $this->assertSame('Pembetulan berdasarkan Daily Figure kilang.', $day25->last_amendment_reason);
        $this->assertSame($this->pegawaiBbj->id, $day25->last_amended_by);
        $this->assertNotNull($day25->last_amended_at);
        $this->assertSame(15.0, (float) $day25->baki_bts_selepas_diproses);
        $this->assertSame(15.0, (float) $day26->baki_bts_semalam);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'amended_with_recalculation',
            'model_type' => 'DailyOperation',
            'model_id' => $day25->id,
            'user_id' => $this->pegawaiBbj->id,
        ]);

        $audit = AuditLog::where('action', 'amended_with_recalculation')->firstOrFail();
        $this->assertSame('Pembetulan berdasarkan Daily Figure kilang.', $audit->new_values['summary']['amendment_reason'] ?? null);
    }

    public function test_initial_creation_does_not_require_amendment_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-27 09:00:00', 'Asia/Kuala_Lumpur'));

        $payload = [
            'tarikh' => '2026-07-27',
            'mill_id' => $this->bbj->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => 10,
            'bts_diproses' => 8,
            'jam_operasi' => 8,
            'downtime_jam' => 1,
            'sebab_downtime' => 'Pembersihan biasa',
            'pengeluaran_cpo' => 5,
            'pengeluaran_pk' => 2,
            'pk_kcp_to_hopper' => 0,
            'produksi_cpo' => 5,
            'produksi_pk' => 2,
            'stok_cpo_yesterday' => 50,
            'stok_pk_yesterday' => 20,
            'stok_cpo' => 50,
            'stok_pk' => 20,
            'baki_bts_semalam' => 0,
            'baki_bts_selepas_diproses' => 2,
            'oer' => 0,
            'ker' => 0,
            'ffa' => 0,
            'moisture' => 0,
            'dirt' => 0,
            'isu_operasi' => 'Tiada',
            'tindakan_pembetulan' => 'Tiada',
            'catatan_tambahan' => 'Ujian',
        ];

        $this->actingAs($this->pegawaiBbj)
            ->post(route('data-harian.store'), $payload)
            ->assertRedirect(route('rekod-harian.index'))
            ->assertSessionHas('success', 'Rekod harian berjaya disimpan.');

        $operation = DailyOperation::whereDate('tarikh', '2026-07-27')->firstOrFail();
        $this->assertNull($operation->last_amendment_reason);
        $this->assertNull($operation->last_amended_by);
        $this->assertNull($operation->last_amended_at);
    }

    private function validPayload(DailyOperation $operation, array $overrides = []): array
    {
        return array_merge([
            'tarikh' => $operation->tarikh->toDateString(),
            'mill_id' => $operation->mill_id,
            'operation_status' => $operation->operation_status ?? 'Operasi',
            'bts_diterima' => $operation->bts_diterima,
            'bts_diproses' => $operation->bts_diproses,
            'jam_operasi' => $operation->jam_operasi,
            'downtime_jam' => $operation->downtime_jam,
            'sebab_downtime' => $operation->sebab_downtime,
            'pengeluaran_cpo' => $operation->pengeluaran_cpo,
            'pengeluaran_pk' => $operation->pengeluaran_pk,
            'pk_kcp_to_hopper' => $operation->pk_kcp_to_hopper,
            'produksi_cpo' => $operation->produksi_cpo,
            'produksi_pk' => $operation->produksi_pk,
            'stok_cpo_yesterday' => $operation->stok_cpo_yesterday,
            'stok_pk_yesterday' => $operation->stok_pk_yesterday,
            'stok_cpo' => $operation->stok_cpo,
            'stok_pk' => $operation->stok_pk,
            'baki_bts_semalam' => $operation->baki_bts_semalam,
            'baki_bts_selepas_diproses' => $operation->baki_bts_selepas_diproses,
            'oer' => $operation->oer,
            'ker' => $operation->ker,
            'ffa' => $operation->ffa,
            'moisture' => $operation->moisture,
            'dirt' => $operation->dirt,
            'isu_operasi' => $operation->isu_operasi,
            'tindakan_pembetulan' => $operation->tindakan_pembetulan,
            'catatan_tambahan' => $operation->catatan_tambahan,
            'amendment_reason' => 'Pembetulan data operasi.',
        ], $overrides);
    }

    private function createOperation(int $millId, string $date, int $officerId, array $overrides = []): DailyOperation
    {
        $defaults = [
            'tarikh' => $date,
            'mill_id' => $millId,
            'shift' => 'Harian',
            'officer_id' => $officerId,
            'operation_status' => 'Operasi',
            'bts_diterima' => 10,
            'bts_diproses' => 8,
            'baki_bts_semalam' => 0,
            'baki_bts_selepas_diproses' => 2,
            'jam_operasi' => 8,
            'downtime_jam' => 1,
            'sebab_downtime' => 'Ujian',
            'pengeluaran_cpo' => 5,
            'pengeluaran_pk' => 2,
            'pk_kcp_to_hopper' => 0,
            'produksi_cpo' => 5,
            'produksi_pk' => 2,
            'stok_cpo' => 50,
            'stok_pk' => 20,
            'stok_cpo_yesterday' => 50,
            'stok_pk_yesterday' => 20,
            'oer' => 0,
            'ker' => 0,
            'ffa' => 0,
            'moisture' => 0,
            'dirt' => 0,
            'throughput' => 0,
            'utilisation_rate' => 0,
            'isu_operasi' => 'Tiada',
            'tindakan_pembetulan' => 'Tiada',
            'catatan_tambahan' => 'Ujian',
            'status' => 'submitted',
        ];

        return DailyOperation::create(array_merge($defaults, $overrides));
    }
}
