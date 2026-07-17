<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KpiIndicatorSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class KpiTargetControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $kbbId;
    private int $kkhgId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kbbId = $this->createMill('BBJ', true)->id;
        $this->kkhgId = $this->createMill('KHG', true)->id;
    }

    public function test_non_admin_cannot_access_kpi_settings(): void
    {
        $user = $this->createUserWithRole('pegawai_kilang');

        $this->actingAs($user)
            ->get(route('kpi.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_kpi_settings(): void
    {
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->get(route('kpi.index'))
            ->assertOk();
    }

    public function test_kpi_page_renders_each_monthly_input_name_once_per_indicator_and_month(): void
    {
        $admin = $this->createUserWithRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('kpi.index', ['scope' => (string) $this->kbbId, 'year' => 2026]))
            ->assertOk();

        $html = $response->getContent();
        $applicableMonths = app(KpiEvaluationService::class)->getApplicableMonths(2026);

        foreach (KpiEvaluationService::indicatorMap() as $code => $indicator) {
            if (($indicator['evaluation_basis'] ?? 'direct_value') !== 'monthly_flow') {
                continue;
            }

            foreach ($applicableMonths as $month) {
                $greenName = sprintf('name="settings[%s][monthly_targets][%d][green]"', $code, $month);
                $redName = sprintf('name="settings[%s][monthly_targets][%d][red]"', $code, $month);

                $this->assertSame(1, substr_count($html, $greenName), 'Duplicate green input found for ' . $code . ' month ' . $month);
                $this->assertSame(1, substr_count($html, $redName), 'Duplicate red input found for ' . $code . ' month ' . $month);
            }
        }
    }

    public function test_invalid_numeric_input_is_rejected_and_not_converted_to_null(): void
    {
        $admin = $this->createUserWithRole('admin');
        $payload = $this->basePayload([
            'oer' => [
                'green_threshold' => '10,8OO',
                'red_threshold' => 5,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_negative_values_are_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');
        $payload = $this->basePayload([
            'oer' => [
                'green_threshold' => -1,
                'red_threshold' => 5,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_invalid_or_inactive_mill_scope_is_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');

        $inactiveMillId = $this->createMill('INACTIVE', false)->id;

        $invalidPayload = $this->basePayload();
        $invalidPayload['scope'] = 'abc';

        $inactivePayload = $this->basePayload();
        $inactivePayload['scope'] = (string) $inactiveMillId;

        $globalPayload = $this->basePayload();
        $globalPayload['scope'] = 'global';

        $this->actingAs($admin)
            ->post(route('kpi.store'), $invalidPayload)
            ->assertSessionHasErrors('scope');

        $this->actingAs($admin)
            ->post(route('kpi.store'), $inactivePayload)
            ->assertSessionHasErrors('scope');

        $this->actingAs($admin)
            ->post(route('kpi.store'), $globalPayload)
            ->assertSessionHasErrors('scope');

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_unknown_indicator_input_is_ignored_and_cannot_alter_trusted_catalogue_values(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload();
        $payload['settings']['evil_indicator'] = [
            'indicator_name' => 'HACK',
            'unit' => 'XYZ',
            'evaluation_direction' => 'lower_is_better',
            'evaluation_basis' => 'direct_value',
            'green_threshold' => 1,
            'red_threshold' => 2,
            'is_active' => 1,
        ];

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $this->assertDatabaseMissing('kpi_indicator_settings', [
            'indicator_code' => 'evil_indicator',
        ]);

        $oer = KpiIndicatorSetting::where('indicator_code', 'oer')->firstOrFail();
        $this->assertSame('OER', $oer->indicator_name);
        $this->assertSame('%', $oer->unit);
        $this->assertSame('higher_is_better', $oer->evaluation_direction);
    }

    public function test_monthly_flow_saves_green_and_red_in_mt(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => 8640],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $setting = KpiIndicatorSetting::where('indicator_code', 'bts_diproses')->firstOrFail();
        $this->assertSame('MT', $setting->unit);
        $this->assertNull($setting->green_threshold);
        $this->assertNull($setting->red_threshold);
        $this->assertSame(10800.0, (float) $setting->monthly_targets['7']['green']);
        $this->assertSame(8640.0, (float) $setting->monthly_targets['7']['red']);
    }

    public function test_2026_saves_only_july_to_december_monthly_values(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    6 => ['green' => 9999, 'red' => 8888],
                    7 => ['green' => 10800, 'red' => 8640],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $targets = KpiIndicatorSetting::where('indicator_code', 'bts_diproses')->firstOrFail()->monthly_targets;
        $this->assertArrayNotHasKey('6', $targets);
        $this->assertArrayHasKey('7', $targets);
    }

    public function test_direct_value_indicator_does_not_save_monthly_targets(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'oer' => [
                'green_threshold' => 21,
                'red_threshold' => 19,
                'monthly_targets' => [
                    7 => ['green' => 111, 'red' => 100],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $setting = KpiIndicatorSetting::where('indicator_code', 'oer')->firstOrFail();
        $this->assertSame([], $setting->monthly_targets);
        $this->assertSame(21.0, (float) $setting->green_threshold);
        $this->assertSame(19.0, (float) $setting->red_threshold);
    }

    public function test_one_sided_threshold_input_is_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');

        $directPayload = $this->basePayload([
            'oer' => [
                'green_threshold' => 21,
                'red_threshold' => null,
            ],
        ]);

        $monthlyPayload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => null],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $directPayload)
            ->assertSessionHasErrors('settings');

        $this->actingAs($admin)
            ->post(route('kpi.store'), $monthlyPayload)
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_browser_style_monthly_payload_with_green_only_returns_validation_error_and_saves_nothing(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => '1000', 'red' => ''],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_browser_style_monthly_payload_with_green_and_red_saves_exact_values(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => '1000', 'red' => '800'],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $setting = KpiIndicatorSetting::where('indicator_code', 'bts_diproses')
            ->where('mill_id', $this->kbbId)
            ->where('year', 2026)
            ->firstOrFail();

        $this->assertSame(1000.0, (float) $setting->monthly_targets['7']['green']);
        $this->assertSame(800.0, (float) $setting->monthly_targets['7']['red']);
    }

    public function test_invalid_threshold_order_is_rejected(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'downtime' => [
                'green_threshold' => 6,
                'red_threshold' => 2,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
    }

    public function test_validation_failure_in_later_indicator_causes_zero_changes(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => 8640],
                ],
            ],
            'oer' => [
                'green_threshold' => 21,
                'red_threshold' => null,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertSessionHasErrors('settings');

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_exception_rolls_back_all_kpi_changes(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => 8640],
                ],
            ],
        ]);

        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::flushEventListeners();
        AuditLog::creating(function (): void {
            throw new RuntimeException('Forced audit failure');
        });

        try {
            $this->actingAs($admin)
                ->post(route('kpi.store'), $payload)
                ->assertSessionHasErrors('settings');
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertDatabaseCount('kpi_indicator_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_successful_save_writes_all_settings_and_audit_logs(): void
    {
        $admin = $this->createUserWithRole('admin');

        $payload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => 8640],
                ],
                'is_active' => 1,
            ],
            'oer' => [
                'green_threshold' => 21,
                'red_threshold' => 19,
                'is_active' => 1,
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $expectedIndicators = count(KpiEvaluationService::indicatorMap());

        $this->assertDatabaseCount('kpi_indicator_settings', $expectedIndicators);
        $this->assertDatabaseCount('audit_logs', $expectedIndicators);
    }

    public function test_kbb_and_kkhg_values_remain_independent_in_controller_saves(): void
    {
        $admin = $this->createUserWithRole('admin');

        $kbbPayload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 14000, 'red' => 11200],
                ],
            ],
        ]);
        $kbbPayload['scope'] = (string) $this->kbbId;

        $kkhgPayload = $this->basePayload([
            'bts_diproses' => [
                'monthly_targets' => [
                    7 => ['green' => 10800, 'red' => 8640],
                ],
            ],
        ]);
        $kkhgPayload['scope'] = (string) $this->kkhgId;

        $this->actingAs($admin)
            ->post(route('kpi.store'), $kbbPayload)
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('kpi.store'), $kkhgPayload)
            ->assertRedirect();

        $kbbSetting = KpiIndicatorSetting::where('indicator_code', 'bts_diproses')->where('mill_id', $this->kbbId)->firstOrFail();
        $kkhgSetting = KpiIndicatorSetting::where('indicator_code', 'bts_diproses')->where('mill_id', $this->kkhgId)->firstOrFail();

        $this->assertSame(14000.0, (float) $kbbSetting->monthly_targets['7']['green']);
        $this->assertSame(10800.0, (float) $kkhgSetting->monthly_targets['7']['green']);
    }

    public function test_legacy_global_kpi_records_remain_intact(): void
    {
        $legacy = \App\Models\KpiTarget::create([
            'mill_id' => null,
            'oer_target' => 20.0,
            'ker_target' => 5.0,
            'ffa_max' => 5.0,
            'downtime_max_hours' => 2.0,
            'effective_year' => 2026,
            'is_active' => true,
        ]);

        $admin = $this->createUserWithRole('admin');
        $payload = $this->basePayload();

        $this->actingAs($admin)
            ->post(route('kpi.store'), $payload)
            ->assertRedirect();

        $legacy->refresh();
        $this->assertSame(20.0, (float) $legacy->oer_target);
        $this->assertSame(5.0, (float) $legacy->ffa_max);
    }

    private function basePayload(array $overrides = []): array
    {
        $settings = [];
        foreach (KpiEvaluationService::indicatorMap() as $code => $indicator) {
            $settings[$code] = [
                'green_threshold' => null,
                'red_threshold' => null,
                'period_target' => null,
                'monthly_targets' => [],
                'is_active' => 1,
            ];
        }

        foreach ($overrides as $code => $rows) {
            $settings[$code] = array_replace_recursive($settings[$code] ?? [], $rows);
        }

        return [
            'scope' => (string) $this->kbbId,
            'year' => 2026,
            'settings' => $settings,
        ];
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::create([
            'name' => $roleName,
            'label' => ucfirst(str_replace('_', ' ', $roleName)),
        ]);

        return User::create([
            'name' => strtoupper($roleName) . ' User',
            'email' => $roleName . uniqid('', true) . '@example.com',
            'password' => 'password123',
            'role_id' => $role->id,
            'mill_id' => null,
            'is_active' => true,
        ]);
    }

    private function createMill(string $code, bool $isActive): \App\Models\Mill
    {
        return \App\Models\Mill::create([
            'name' => 'Kilang ' . $code,
            'code' => $code,
            'location' => 'Johor',
            'is_active' => $isActive,
        ]);
    }
}
