<?php

namespace App\Http\Controllers;

use App\Services\FfbDashboardService;
use App\Services\FfbReceiveReportExportService;
use App\Services\FfbReceiveReportService;
use App\Models\WeighbridgeCentre;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FfbReceiveReportController extends Controller
{
    public function index(Request $request, FfbDashboardService $dashboard, FfbReceiveReportService $reports)
    {
        $mills = $dashboard->mills($request->user());
        $input = $this->input($request, false);
        $report = $filters = null;
        if (isset($input['mill'])) {
            $filters = $this->filters($request, $dashboard, $input);
            $report = $reports->generate($request->user(), $filters);
        }

        return view('ffb-receive.reports.index', compact('mills', 'report', 'filters', 'input'));
    }

    public function pdf(Request $request, FfbDashboardService $dashboard, FfbReceiveReportService $reports, FfbReceiveReportExportService $exports)
    {
        $input = $this->input($request, true);
        $report = $reports->generate($request->user(), $this->filters($request, $dashboard, $input));

        return response($exports->pdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$exports->filename($report, 'pdf').'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function excel(Request $request, FfbDashboardService $dashboard, FfbReceiveReportService $reports, FfbReceiveReportExportService $exports)
    {
        $input = $this->input($request, true);
        $report = $reports->generate($request->user(), $this->filters($request, $dashboard, $input), includeTickets: true);
        $book = $exports->excel($report);

        return response()->streamDownload(function () use ($book): void {
            try {
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $exports->filename($report, 'xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function input(Request $request, bool $export): array
    {
        return array_filter($request->validate([
            'mill' => [$export ? 'required' : 'sometimes', Rule::in(['BBJ', 'KHG'])],
            'type' => ['sometimes', Rule::in(['hq', 'technical'])],
            'categories' => ['sometimes', 'required', 'array', 'min:1', 'max:3'],
            'categories.*' => ['required', 'string', 'distinct', Rule::in(WeighbridgeCentre::SOURCE_CATEGORIES)],
            'period' => [$export ? 'required' : 'sometimes', Rule::in(['daily', 'monthly', 'yearly', 'range'])],
            'date' => ['nullable', 'required_if:period,daily', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'month' => ['nullable', 'required_if:period,monthly', 'date_format:Y-m', 'after_or_equal:1000-01'],
            'year' => ['nullable', 'required_if:period,yearly', 'integer', 'between:1000,9999'],
            'start' => ['nullable', 'required_if:period,range', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'end' => ['nullable', 'required_if:period,range', 'date_format:Y-m-d', 'after_or_equal:start'],
        ], [
            'mill.required' => 'Sila pilih satu kilang untuk menjana laporan.',
            'mill.in' => 'Laporan memerlukan satu kilang: KBB atau Kahang.',
            'end.after_or_equal' => 'Tarikh akhir mesti pada atau selepas tarikh mula.',
            'categories.required' => 'Sila pilih sekurang-kurangnya satu kategori BTS.',
            'categories.min' => 'Sila pilih sekurang-kurangnya satu kategori BTS.',
        ]), fn ($value) => $value !== null && $value !== '');
    }

    private function filters(Request $request, FfbDashboardService $dashboard, array $input): array
    {
        $latest = $dashboard->latestDate($request->user(), $input['mill']);

        return array_merge($dashboard->period($input, $latest ?? now('Asia/Kuala_Lumpur')->toDateString()), [
            'categories' => $input['categories'] ?? ['ppnj_ppk_integrated'],
        ]);
    }
}
