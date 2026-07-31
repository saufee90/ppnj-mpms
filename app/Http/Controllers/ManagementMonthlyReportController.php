<?php

namespace App\Http\Controllers;

use App\Models\DailyOperation;
use App\Models\Mill;
use App\Services\ManagementMonthlyPdfService;
use App\Services\ManagementMonthlyReportPresentationService;
use App\Services\ManagementMonthlyReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ManagementMonthlyReportController extends Controller
{
    public function index(
        Request $request,
        ManagementMonthlyReportService $reportService,
        ManagementMonthlyReportPresentationService $presentationService
    )
    {
        [$dataset, $month, $year] = $this->resolveReport($request, $reportService);
        $presentation = $presentationService->prepare($dataset);
        $user = $request->user();
        $mills = $user->canViewAllMills()
            ? Mill::query()->where('is_active', true)->orderBy('name')->get()
            : Mill::query()->whereKey($user->mill_id)->get();
        $earliestDate = DailyOperation::query()->min('tarikh');
        $earliestYear = $earliestDate ? Carbon::parse($earliestDate)->year : $year;
        $years = range(max(2024, $earliestYear), max($year, now()->year));

        return view('laporan-pengurusan-bulanan.index', compact(
            'dataset',
            'presentation',
            'mills',
            'month',
            'year',
            'years'
        ));
    }

    public function downloadPdf(
        Request $request,
        ManagementMonthlyReportService $reportService,
        ManagementMonthlyReportPresentationService $presentationService,
        ManagementMonthlyPdfService $pdfService
    ) {
        [$dataset] = $this->resolveReport($request, $reportService);
        $report = $pdfService->generate($dataset, $presentationService->prepare($dataset));

        return response($report['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $report['filename'] . '"',
        ]);
    }

    private function resolveReport(Request $request, ManagementMonthlyReportService $reportService): array
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

        return [
            $reportService->generate($user, $year, $month, $requestedMillId),
            $month,
            $year,
        ];
    }
}
