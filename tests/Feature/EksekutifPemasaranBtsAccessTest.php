<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckRole;
use App\Http\Controllers\ManagementMonthlyReportController;
use App\Http\Controllers\ReportController;
use App\Models\FfbImportBatch;
use App\Models\Role;
use App\Models\User;
use App\Policies\FfbImportBatchPolicy;
use App\Services\FfbDashboardService;
use App\Services\ManagementMonthlyReportPresentationService;
use App\Services\ManagementMonthlyReportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EksekutifPemasaranBtsAccessTest extends TestCase
{
    public function test_role_seeder_adds_the_role_idempotently_without_changing_existing_ids(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('label');
            $table->timestamps();
        });
        DB::table('roles')->insert([
            'id' => 17,
            'name' => Role::ADMIN,
            'label' => 'Original Admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RoleSeeder::class)->run();
        app(RoleSeeder::class)->run();

        $this->assertSame(17, (int) DB::table('roles')->where('name', Role::ADMIN)->value('id'));
        $this->assertSame('Original Admin', DB::table('roles')->where('name', Role::ADMIN)->value('label'));
        $this->assertSame(1, DB::table('roles')->where('name', Role::EKSEKUTIF_PEMASARAN_BTS)->count());
        $this->assertSame(
            'Eksekutif Pemasaran BTS',
            DB::table('roles')->where('name', Role::EKSEKUTIF_PEMASARAN_BTS)->value('label')
        );
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_marketing_executive_can_view_both_mills_but_cannot_create_or_confirm_receipts(): void
    {
        $user = $this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS);
        $policy = new FfbImportBatchPolicy();
        $bbjBatch = new FfbImportBatch(['mill_id' => 1]);
        $khgBatch = new FfbImportBatch(['mill_id' => 2]);

        $this->assertTrue($user->canViewFfbReceiveReports());
        $this->assertFalse($user->canViewAllMills());
        $this->assertTrue($user->canSelectDashboardMill());
        $this->assertFalse($user->canEditData());
        $this->assertFalse($user->canDeleteData());
        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $bbjBatch));
        $this->assertTrue($policy->view($user, $khgBatch));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->confirm($user, $bbjBatch));
        $this->assertFalse($policy->confirm($user, $khgBatch));
    }

    public function test_ffb_dashboard_service_exposes_both_mills_and_confirmed_receipts(): void
    {
        Schema::create('mills', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('ffb_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('mill_id');
            $table->string('status');
            $table->boolean('active_slot')->default(true);
        });
        DB::table('mills')->insert([
            ['id' => 1, 'code' => 'BBJ', 'name' => 'Bukit Bujang', 'is_active' => true],
            ['id' => 2, 'code' => 'KHG', 'name' => 'Kahang', 'is_active' => true],
        ]);
        DB::table('ffb_import_batches')->insert([
            ['mill_id' => 1, 'status' => 'confirmed', 'active_slot' => true],
            ['mill_id' => 2, 'status' => 'confirmed', 'active_slot' => true],
            ['mill_id' => 2, 'status' => 'uploaded', 'active_slot' => true],
        ]);

        $service = app(FfbDashboardService::class);
        $user = $this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS);
        $mills = $service->mills($user);
        $batches = $service->batches($user, 'all')->get();

        $this->assertSame(['BBJ', 'KHG'], $mills->pluck('code')->all());
        $this->assertSame(
            [1, 2],
            $batches->pluck('mill_id')->map(fn ($id) => (int) $id)->sort()->values()->all()
        );
        $this->assertCount(1, $service->batches($user, 'KHG')->get());
    }

    public function test_existing_reports_allow_marketing_role_to_view_both_mills_and_filter_each(): void
    {
        $this->createReportTables();
        DB::table('mills')->insert([
            ['id' => 1, 'code' => 'BBJ', 'name' => 'Bukit Bujang', 'is_active' => true],
            ['id' => 2, 'code' => 'KHG', 'name' => 'Kahang', 'is_active' => true],
        ]);
        DB::table('daily_operations')->insert([
            $this->reportRow(1, '2026-07-10', 100),
            $this->reportRow(2, '2026-07-11', 200),
        ]);

        $user = $this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS);
        $reportController = app(ReportController::class);
        $combined = $reportController->index($this->reportRequest($user));

        $this->assertSame([1, 2], $combined->getData()['records']->pluck('mill_id')->all());
        foreach ([1, 2] as $millId) {
            $filtered = $reportController->index($this->reportRequest($user, ['mill_id' => $millId]));
            $this->assertSame([$millId], $filtered->getData()['records']->pluck('mill_id')->all());
        }

        $monthlyService = \Mockery::mock(ManagementMonthlyReportService::class);
        $monthlyService->shouldReceive('generate')->times(3)->andReturnUsing(
            function (User $requestedUser, int $year, int $month, ?int $millId): array {
                $this->assertTrue($requestedUser->isEksekutifPemasaranBts());
                $this->assertSame(2026, $year);
                $this->assertSame(7, $month);
                $ids = $millId === null ? [1, 2] : [$millId];

                return [
                    'meta' => ['scope_mill_id' => $millId, 'scope_type' => $millId ? 'single_mill' : 'all_mills'],
                    'mills' => array_map(fn (int $id) => ['mill' => ['id' => $id]], $ids),
                ];
            }
        );
        $presentation = \Mockery::mock(ManagementMonthlyReportPresentationService::class);
        $presentation->shouldReceive('prepare')->times(3)->andReturn([]);
        $monthlyController = app(ManagementMonthlyReportController::class);

        foreach ([null, 1, 2] as $millId) {
            $request = $this->reportRequest($user, array_filter([
                'bulan' => 7,
                'tahun' => 2026,
                'mill_id' => $millId,
            ], fn ($value) => $value !== null));
            $view = $monthlyController->index($request, $monthlyService, $presentation);

            $this->assertSame($millId, $view->getData()['dataset']['meta']['scope_mill_id']);
            $this->assertCount($millId === null ? 2 : 1, $view->getData()['dataset']['mills']);
            $this->assertSame(['BBJ', 'KHG'], $view->getData()['mills']->pluck('code')->all());
        }
    }

    public function test_only_dashboard_and_report_routes_include_the_new_role(): void
    {
        foreach ([
            'ffb-receive.dashboard',
            'ffb-receive.reports.index',
            'ffb-receive.reports.pdf',
            'ffb-receive.reports.excel',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertContains('role:admin,pegawai_kilang,pengurus_kilang,pengurusan,eksekutif_pemasaran_bts', $middleware);
        }

        foreach ([
            'ffb-receive.index',
            'ffb-receive.store',
            'ffb-receive.zero-confirm',
            'ffb-receive.confirm',
            'data-harian.store',
            'data-harian.destroy',
            'data-harian.update',
            'kpi.store',
            'users.store',
            'maintenance.update',
        ] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [];
            $this->assertNotContains('role:eksekutif_pemasaran_bts', $middleware);
            $this->assertNotContains('role:admin,pegawai_kilang,pengurus_kilang,pengurusan,eksekutif_pemasaran_bts', $middleware);
        }
    }

    public function test_existing_report_routes_are_authenticated_and_write_routes_keep_their_original_allowlists(): void
    {
        foreach ([
            'laporan.index',
            'laporan.export.pdf',
            'laporan.export.excel',
            'laporan-pengurusan-bulanan.index',
            'laporan-pengurusan-bulanan.pdf',
        ] as $routeName) {
            $this->assertContains(
                'auth',
                Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [],
                $routeName
            );
        }

        foreach ([
            'ffb-receive.store' => 'role:admin,pegawai_kilang,pengurus_kilang,pengurusan',
            'ffb-receive.confirm' => 'role:admin,pegawai_kilang,pengurus_kilang,pengurusan',
            'data-harian.store' => 'role:admin,pegawai_kilang',
            'data-harian.update' => 'role:admin,pegawai_kilang',
            'kpi.store' => 'role:admin',
            'users.store' => 'role:admin',
            'users.update' => 'role:admin',
            'maintenance.update' => 'role:admin',
        ] as $routeName => $expectedMiddleware) {
            $this->assertContains(
                $expectedMiddleware,
                Route::getRoutes()->getByName($routeName)?->gatherMiddleware() ?? [],
                $routeName
            );
        }
    }

    public function test_role_middleware_rejects_receipt_write_route_access(): void
    {
        $user = $this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS);
        $request = Request::create('/ffb-receive', 'POST');
        $request->setUserResolver(fn () => $user);

        try {
            app(CheckRole::class)->handle(
                $request,
                fn () => response('unexpectedly allowed'),
                Role::ADMIN,
                Role::PEGAWAI_KILANG,
                Role::PENGURUS_KILANG,
                Role::PENGURUSAN
            );
            $this->fail('The marketing role must be rejected by the write-route middleware.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_role_middleware_allows_read_only_report_routes(): void
    {
        $request = Request::create('/ffb-receive/reports', 'GET');
        $request->setUserResolver(fn () => $this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS));

        $response = app(CheckRole::class)->handle(
            $request,
            fn () => response('allowed'),
            Role::ADMIN,
            Role::PEGAWAI_KILANG,
            Role::PENGURUS_KILANG,
            Role::PENGURUSAN,
            Role::EKSEKUTIF_PEMASARAN_BTS
        );

        $this->assertSame('allowed', $response->getContent());
    }

    public function test_sidebar_shows_read_only_bts_reports_without_import_or_admin_navigation(): void
    {
        $this->actingAs($this->userWithRole(Role::EKSEKUTIF_PEMASARAN_BTS));
        $html = view('layouts.partials.navigation-links')->render();

        $this->assertStringContainsString('Dashboard BTS', $html);
        $this->assertStringContainsString('Laporan Penerimaan BTS', $html);
        $this->assertStringContainsString('>🧾 Laporan</a>', $html);
        $this->assertStringContainsString('>📑 Prestasi Bulanan</a>', $html);
        $this->assertStringNotContainsString('Import &amp; Pengesahan BTS', $html);
        $this->assertStringNotContainsString('Input Data Harian', $html);
        $this->assertStringNotContainsString('Tetapan KPI', $html);
        $this->assertStringNotContainsString('Pengurusan Pengguna', $html);
    }

    private function userWithRole(string $roleName): User
    {
        $user = new User(['name' => 'Marketing Executive', 'is_active' => true]);
        $user->setRelation('role', new Role(['name' => $roleName]));
        $user->setRelation('mill', null);

        return $user;
    }

    private function createReportTables(): void
    {
        Schema::create('mills', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
        });
        Schema::create('daily_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('mill_id');
            $table->date('tarikh');
            $table->string('operation_status')->nullable();
            $table->decimal('bts_diproses', 12, 2)->default(0);
            $table->decimal('jam_operasi', 12, 2)->default(0);
            $table->decimal('produksi_cpo', 12, 2)->default(0);
            $table->decimal('produksi_pk', 12, 2)->default(0);
            $table->decimal('stok_cpo', 12, 2)->default(0);
            $table->decimal('stok_pk', 12, 2)->default(0);
        });
    }

    private function reportRow(int $millId, string $date, float $processed): array
    {
        return [
            'mill_id' => $millId,
            'tarikh' => $date,
            'operation_status' => 'Operasi',
            'bts_diproses' => $processed,
            'jam_operasi' => 8,
            'produksi_cpo' => 0,
            'produksi_pk' => 0,
            'stok_cpo' => 0,
            'stok_pk' => 0,
        ];
    }

    private function reportRequest(User $user, array $query = []): Request
    {
        $request = app('request');
        $request->query->replace($query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
