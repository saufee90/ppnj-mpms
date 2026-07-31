<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Services\ManagementMonthlyReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManagementMonthlyReportController extends Controller
{
    public function index(Request $request, ManagementMonthlyReportService $reportService)
    {
        $user = $request->user();
        $validated = $request->validate([
            'bulan' => ['nullable', 'integer', 'between:1,12'],
            'tahun' => ['nullable', 'integer', 'between:2024,2100'],
            'mill_id' => [
                'nullable',
                'integer',
                Rule::exists('mills', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $month = (int) ($validated['bulan'] ?? now()->month);
        $year = (int) ($validated['tahun'] ?? now()->year);
        $requestedMillId = $user->canViewAllMills() && isset($validated['mill_id'])
            ? (int) $validated['mill_id']
            : null;
        $dataset = $reportService->generate($user, $year, $month, $requestedMillId);
        $mills = $user->canViewAllMills()
            ? Mill::query()->where('is_active', true)->orderBy('name')->get()
            : Mill::query()->whereKey($user->mill_id)->get();
        $earliestDate = DailyOperation::query()->min('tarikh');
        $earliestYear = $earliestDate ? Carbon::parse($earliestDate)->year : $year;
        $years = range(max(2024, $earliestYear), max($year, now()->year));

        return view('laporan-pengurusan-bulanan.index', compact(
            'dataset',
            'mills',
            'month',
            'year',
            'years'
        ));
    }
}
