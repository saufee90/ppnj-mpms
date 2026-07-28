<?php

namespace Tests\Feature;

use App\Models\DailyOperation;
use App\Models\AuditLog;
use App\Models\Mill;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemMaintenanceManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $pegawai;
    private Mill $mill;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::forgetCache();

        if (! Schema::hasColumn('daily_operations', 'operation_status')) {
            Schema::table('daily_operations', function ($table) {
                $table->string('operation_status')->default('Operasi')->after('officer_id');
            });
        }

        $adminRole = Role::create(['name' => Role::ADMIN, 'label' => 'Admin']);
        $pegawaiRole = Role::create(['name' => Role::PEGAWAI_KILANG, 'label' => 'Pegawai Kilang']);

        $this->mill = Mill::create([
            'name' => 'Kilang Sawit Bukit Bujang',
            'code' => 'BBJ',
            'location' => 'Segamat',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin Tester',
            'email' => 'admin-maint@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $adminRole->id,
            'mill_id' => null,
            'is_active' => true,
        ]);

        $this->pegawai = User::create([
            'name' => 'Pegawai Tester',
            'email' => 'pegawai-maint@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $pegawaiRole->id,
            'mill_id' => $this->mill->id,
            'is_active' => true,
        ]);

        $this->setMaintenanceState(false);
    }

    protected function tearDown(): void
    {
        $this->setMaintenanceState(false);
        SystemSetting::forgetCache();

        parent::tearDown();
    }

    public function test_maintenance_off_allows_normal_access(): void
    {
        $this->get(route('login'))
            ->assertOk();
    }

    public function test_maintenance_on_blocks_guest_with_503(): void
    {
        $this->setMaintenanceState(true);

        $this->get(route('dashboard'))
            ->assertStatus(503)
            ->assertSee('Sistem Sedang Diselenggara', false);
    }

    public function test_maintenance_on_blocks_non_admin_user_with_503(): void
    {
        $this->setMaintenanceState(true);

        $this->actingAs($this->pegawai)
            ->get(route('dashboard'))
            ->assertStatus(503)
            ->assertSee('Sistem Sedang Diselenggara', false);
    }

    public function test_maintenance_on_allows_admin_access(): void
    {
        $this->setMaintenanceState(true);

        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_maintenance_route_does_not_redirect_loop(): void
    {
        $this->setMaintenanceState(true);

        $this->get(route('maintenance.show'))
            ->assertStatus(503)
            ->assertSee('Sistem Sedang Diselenggara', false)
            ->assertHeaderMissing('Location');
    }

    public function test_non_admin_cannot_update_maintenance_settings(): void
    {
        $payload = $this->payload([
            'maintenance_enabled' => 1,
        ]);

        $this->actingAs($this->pegawai)
            ->put(route('maintenance.update'), $payload)
            ->assertForbidden();
    }

    public function test_preview_does_not_change_database_or_write_audit_log(): void
    {
        $this->setMaintenanceState(false, [
            'maintenance_title' => 'Sistem Sedang Diselenggara',
            'maintenance_message' => 'Mesej asal',
        ]);

        $beforeSetting = SystemSetting::getValue('maintenance_title');
        $beforeAuditCount = AuditLog::count();

        $this->actingAs($this->admin)
            ->get(route('maintenance.preview', [
                'maintenance_title' => 'Preview Title',
                'maintenance_message' => 'Preview Message',
            ]))
            ->assertOk()
            ->assertSee('PREVIEW ADMIN', false)
            ->assertSee('Preview Title', false)
            ->assertSee('Preview Message', false);

        $this->assertSame($beforeSetting, SystemSetting::getValue('maintenance_title'));
        $this->assertSame($beforeAuditCount, AuditLog::count());
    }

    public function test_retry_after_header_is_not_sent_for_past_end_time(): void
    {
        $this->setMaintenanceState(true, [
            'maintenance_end_at' => '2026-07-27 10:00:00',
        ]);

        $this->get(route('maintenance.show'))
            ->assertStatus(503)
            ->assertHeaderMissing('Retry-After');
    }

    public function test_admin_can_activate_and_disable_maintenance(): void
    {
        $activatePayload = $this->payload([
            'maintenance_enabled' => 1,
            'maintenance_title' => 'Sistem Sedang Diselenggara',
            'maintenance_message' => 'Penyelenggaraan sedang dijalankan.',
            'maintenance_end_at' => '2026-07-29T10:00',
        ]);

        $this->actingAs($this->admin)
            ->put(route('maintenance.update'), $activatePayload)
            ->assertRedirect(route('maintenance.index'))
            ->assertSessionHas('success', 'Tetapan maintenance berjaya disimpan.');

        $this->assertTrue(SystemSetting::maintenanceEnabled());

        $activateLog = AuditLog::latest('id')->first();
        $this->assertNotNull($activateLog);
        $this->assertSame('Mengaktifkan Maintenance Mode', $activateLog->action);
        $this->assertFalse($activateLog->old_values['maintenance_enabled']);
        $this->assertTrue($activateLog->new_values['maintenance_enabled']);
        $this->assertSame('system_update', $activateLog->new_values['maintenance_type']);
        $this->assertSame('Sistem Sedang Diselenggara', $activateLog->new_values['maintenance_title']);
        $this->assertSame('2026-07-29 10:00:00', $activateLog->new_values['maintenance_end_at']);
        $this->assertSame('1.0.5', $activateLog->new_values['system_version']);
        $this->assertArrayHasKey('summary', $activateLog->new_values);
        $this->assertSame('Mengaktifkan Maintenance Mode', $activateLog->new_values['summary']['description']);
        $this->assertNotEmpty($activateLog->new_values['summary']['changes']);
        $this->assertArrayNotHasKey('maintenance_message', $activateLog->old_values);
        $this->assertArrayNotHasKey('maintenance_notes', $activateLog->old_values);

        $this->actingAs($this->admin)
            ->post(route('maintenance.disable'))
            ->assertRedirect(route('maintenance.index'));

        $this->assertFalse(SystemSetting::maintenanceEnabled());

        $disableLog = AuditLog::latest('id')->first();
        $this->assertNotNull($disableLog);
        $this->assertSame('Menyahaktifkan Maintenance Mode', $disableLog->action);
        $this->assertTrue($disableLog->old_values['maintenance_enabled']);
        $this->assertFalse($disableLog->new_values['maintenance_enabled']);
        $this->assertSame('Menyahaktifkan Maintenance Mode', $disableLog->new_values['summary']['description']);
    }

    public function test_update_without_real_changes_does_not_create_audit_log(): void
    {
        $this->setMaintenanceState(false, [
            'maintenance_type' => 'system_update',
            'maintenance_title' => 'Sistem Sedang Diselenggara',
            'maintenance_message' => 'Mesej asal',
            'maintenance_notes' => null,
            'maintenance_end_at' => null,
            'system_version' => '1.0.5',
        ]);

        $beforeCount = AuditLog::count();

        $this->actingAs($this->admin)
            ->put(route('maintenance.update'), $this->payload())
            ->assertRedirect(route('maintenance.index'));

        $this->assertSame($beforeCount, AuditLog::count());
    }

    public function test_json_request_receives_503_response(): void
    {
        $this->setMaintenanceState(true);

        $this->actingAs($this->pegawai)
            ->withHeaders(['Accept' => 'application/json'])
            ->get(route('dashboard'))
            ->assertStatus(503)
            ->assertJson([
                'maintenance' => true,
            ]);
    }

    public function test_maintenance_page_responds_with_503(): void
    {
        $this->setMaintenanceState(true);

        $this->get(route('maintenance.show'))
            ->assertStatus(503)
            ->assertSee('Mill Performance System', false)
            ->assertSee('Sistem Sedang Diselenggara', false);
    }

    public function test_retry_after_header_is_sent_when_end_time_is_valid(): void
    {
        $this->setMaintenanceState(true, [
            'maintenance_end_at' => '2026-07-29 10:00:00',
        ]);

        $this->get(route('maintenance.show'))
            ->assertStatus(503)
            ->assertHeader('Retry-After');
    }

    private function setMaintenanceState(bool $enabled, array $overrides = []): void
    {
        $payload = array_merge(SystemSetting::DEFAULTS, [
            'maintenance_enabled' => $enabled,
        ], $overrides);

        foreach ($payload as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        SystemSetting::forgetCache();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'maintenance_enabled' => 0,
            'maintenance_type' => 'system_update',
            'maintenance_title' => 'Sistem Sedang Diselenggara',
            'maintenance_message' => 'Sistem MPS sedang menjalani kerja-kerja penyelenggaraan dan penambahbaikan bagi meningkatkan prestasi serta kestabilan sistem. Segala kesulitan amat dikesali.',
            'maintenance_notes' => null,
            'maintenance_end_at' => null,
            'system_version' => '1.0.5',
        ], $overrides);
    }
}
