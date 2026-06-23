<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\KpiTarget;
use App\Models\Mill;
use Illuminate\Http\Request;

class KpiTargetController extends Controller
{
    public function index(Request $request)
    {
        $mills = Mill::where('is_active', true)->get();
        $targets = KpiTarget::with('mill')->orderByDesc('effective_year')->get();

        return view('kpi.index', compact('mills', 'targets'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'mill_id' => ['nullable', 'exists:mills,id'],
            'oer_target' => ['required', 'numeric', 'min:0', 'max:100'],
            'ker_target' => ['required', 'numeric', 'min:0', 'max:100'],
            'ffa_max' => ['required', 'numeric', 'min:0', 'max:100'],
            'downtime_max_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'effective_year' => ['required', 'digits:4'],
        ]);
        $validated['is_active'] = true;

        $target = KpiTarget::create($validated);

        AuditLog::record('created', $target, null, $target->toArray());

        return redirect()->route('kpi.index')->with('success', 'Sasaran KPI berjaya ditambah.');
    }

    public function update(KpiTarget $kpi_target, Request $request)
    {
        $validated = $request->validate([
            'oer_target' => ['required', 'numeric', 'min:0', 'max:100'],
            'ker_target' => ['required', 'numeric', 'min:0', 'max:100'],
            'ffa_max' => ['required', 'numeric', 'min:0', 'max:100'],
            'downtime_max_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $validated['is_active'] = $request->boolean('is_active');

        $old = $kpi_target->toArray();
        $kpi_target->update($validated);

        AuditLog::record('updated', $kpi_target, $old, $kpi_target->toArray());

        return redirect()->route('kpi.index')->with('success', 'Sasaran KPI berjaya dikemaskini.');
    }
}
