<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DailyOperationController;
use App\Http\Controllers\PerformanceAnalysisController;
use App\Http\Controllers\MillComparisonController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\KpiTargetController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest Routes
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::get('/', function () {
    return redirect()->route('login');
});

/*
|--------------------------------------------------------------------------
| Authenticated Routes (semua role)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // 1. Dashboard - semua role boleh lihat (data dikawal dalam controller ikut role)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // 4. Analisis Prestasi - semua role
    Route::get('/analisis-prestasi', [PerformanceAnalysisController::class, 'index'])->name('analisis.index');

    // 5. Perbandingan Kilang - semua role
    Route::get('/perbandingan-kilang', [MillComparisonController::class, 'index'])->name('perbandingan.index');

    // 6. Laporan - semua role boleh lihat & export (edit data tetap dikawal asingan)
    Route::prefix('laporan')->name('laporan.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/export-pdf', [ReportController::class, 'exportPdf'])->name('export.pdf');
        Route::get('/export-excel', [ReportController::class, 'exportExcel'])->name('export.excel');
    });

    /*
    |--------------------------------------------------------------------------
    | 2 & 3. Input & Senarai Data Harian - Admin & Pegawai Kilang sahaja
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,pegawai_kilang')->group(function () {
        Route::prefix('data-harian')->name('data-harian.')->group(function () {
            Route::get('/', [DailyOperationController::class, 'index'])->name('index');
            Route::get('/create', [DailyOperationController::class, 'create'])->name('create');
            Route::post('/', [DailyOperationController::class, 'store'])->name('store');
            Route::get('/{daily_operation}/edit', [DailyOperationController::class, 'edit'])
                ->middleware('restrict.mill')->name('edit');
            Route::put('/{daily_operation}', [DailyOperationController::class, 'update'])
                ->middleware('restrict.mill')->name('update');
        });
    });

    // Padam data - Admin sahaja
    Route::middleware('role:admin')->group(function () {
        Route::delete('/data-harian/{daily_operation}', [DailyOperationController::class, 'destroy'])
            ->name('data-harian.destroy');
    });

    // Lihat senarai rekod - semua role (data difilter ikut role dalam controller)
    Route::get('/rekod-harian', [DailyOperationController::class, 'records'])->name('rekod-harian.index');
    Route::get('/rekod-harian/{daily_operation}', [DailyOperationController::class, 'show'])->name('rekod-harian.show');

    /*
    |--------------------------------------------------------------------------
    | 7. Tetapan KPI - Admin sahaja
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('tetapan-kpi')->name('kpi.')->group(function () {
        Route::get('/', [KpiTargetController::class, 'index'])->name('index');
        Route::post('/', [KpiTargetController::class, 'store'])->name('store');
        Route::put('/{kpi_target}', [KpiTargetController::class, 'update'])->name('update');
    });

    /*
    |--------------------------------------------------------------------------
    | 8. Pengurusan Pengguna - Admin sahaja
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('pengurusan-pengguna')->name('users.')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('index');
        Route::get('/create', [UserManagementController::class, 'create'])->name('create');
        Route::post('/', [UserManagementController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UserManagementController::class, 'update'])->name('update');
        Route::delete('/{user}', [UserManagementController::class, 'destroy'])->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | 9. Log Aktiviti - Admin sahaja
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->get('/log-aktiviti', [AuditLogController::class, 'index'])->name('audit.index');
});
