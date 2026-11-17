<?php

namespace App\Http\Controllers;

use App\Services\FfbDashboardService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FfbDashboardController extends Controller
{
    public function index(Request $request, FfbDashboardService $dashboard): View
    {
        $user = $request->user();
        $mills = $dashboard->mills($user);
        $input = $request->validate([
            'mill' => ['sometimes', Rule::in(['all', 'BBJ', 'KHG'])],
            'period' => ['sometimes', Rule::in(['daily', 'monthly', 'yearly', 'range'])],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'month' => ['nullable', 'date_format:Y-m', 'after_or_equal:1000-01'],
            'year' => ['nullable', 'integer', 'between:1000,9999'],
            'start' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01'],
            'end' => ['required_if:period,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ], [
            'mill.in' => 'Sila pilih kilang yang sah.',
            'period.in' => 'Sila pilih tempoh yang sah.',
            'start.required_if' => 'Tarikh mula diperlukan untuk julat tarikh.',
            'end.required_if' => 'Tarikh akhir diperlukan untuk julat tarikh.',
            'end.after_or_equal' => 'Tarikh akhir mesti pada atau selepas tarikh mula.',
        ]);
        $input = array_filter($input, fn ($value) => $value !== null && $value !== '');
        $input['mill'] ??= $user->canViewFfbReceiveReports() ? 'all' : ($mills->first()?->code ?? '');
        $latest = $dashboard->latestDate($user, $input['mill']);
        $filters = $dashboard->period($input, $latest ?? now('Asia/Kuala_Lumpur')->toDateString());
        $data = $dashboard->generate($user, $filters);

        return view('ffb-receive.dashboard', compact('data', 'filters', 'mills', 'latest'));
    }
}
