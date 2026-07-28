<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SystemMaintenanceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', SystemSetting::class);

        return view('system-maintenance.index', [
            'settings' => SystemSetting::mergedValues(),
            'maintenanceOptions' => SystemSetting::maintenanceOptions(),
            'history' => AuditLog::with('user')
                ->whereIn('action', [
                    'Mengaktifkan Maintenance Mode',
                    'Menyahaktifkan Maintenance Mode',
                    'Mengemaskini Tetapan Maintenance',
                ])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('update', SystemSetting::class);

        $validated = $this->validatePayload($request);
        $oldValues = $this->auditValues(SystemSetting::mergedValues());

        DB::transaction(function () use ($validated, $oldValues): void {
            foreach ($validated as $key => $value) {
                SystemSetting::setValue($key, $value);
            }

            SystemSetting::forgetCache();
            $newValues = $this->auditValues(SystemSetting::mergedValues());

            if (! $this->hasAuditChanges($oldValues, $newValues)) {
                return;
            }

            $action = $this->resolveAction($oldValues, $newValues);

            AuditLog::record($action, null, $oldValues, $this->withSummary($action, $oldValues, $newValues));
        });

        SystemSetting::forgetCache();

        return redirect()->route('maintenance.index')->with('success', 'Tetapan maintenance berjaya disimpan.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->authorize('update', SystemSetting::class);

        $oldValues = $this->auditValues(SystemSetting::mergedValues());
        DB::transaction(function () use ($oldValues): void {
            SystemSetting::setValue('maintenance_enabled', false);
            SystemSetting::forgetCache();
            $newValues = $this->auditValues(SystemSetting::mergedValues());

            if ($this->hasAuditChanges($oldValues, $newValues) && (bool) ($oldValues['maintenance_enabled'] ?? false)) {
                AuditLog::record('Menyahaktifkan Maintenance Mode', null, $oldValues, $this->withSummary('Menyahaktifkan Maintenance Mode', $oldValues, $newValues));
            }
        });

        SystemSetting::forgetCache();

        return redirect()->route('maintenance.index')->with('success', 'Maintenance mode berjaya dimatikan.');
    }

    public function preview(Request $request)
    {
        $this->authorize('viewAny', SystemSetting::class);

        $settings = SystemSetting::mergedValues($request->query());

        return view('maintenance', [
            'title' => $settings['maintenance_title'],
            'message' => $settings['maintenance_message'],
            'notes' => $this->splitNotes($settings['maintenance_notes']),
            'maintenance_type' => $settings['maintenance_type'],
            'maintenance_label' => SystemSetting::maintenanceLabel((string) $settings['maintenance_type']),
            'maintenance_end_at' => $settings['maintenance_end_at'],
            'system_version' => $settings['system_version'],
            'preview' => true,
        ]);
    }

    public function show(Request $request)
    {
        $settings = SystemSetting::mergedValues();

        if (! SystemSetting::maintenanceEnabled()) {
            return redirect()->route('login');
        }

        $response = response()->view('maintenance', [
            'title' => $settings['maintenance_title'],
            'message' => $settings['maintenance_message'],
            'notes' => $this->splitNotes($settings['maintenance_notes']),
            'maintenance_type' => $settings['maintenance_type'],
            'maintenance_label' => SystemSetting::maintenanceLabel((string) $settings['maintenance_type']),
            'maintenance_end_at' => $settings['maintenance_end_at'],
            'system_version' => $settings['system_version'],
            'preview' => false,
        ], 503);

        if ($retryAfter = SystemSetting::retryAfterSeconds($settings['maintenance_end_at'])) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'maintenance_enabled' => ['nullable', 'boolean'],
            'maintenance_type' => ['required', Rule::in([
                'scheduled_maintenance',
                'system_update',
                'deployment',
                'recalculation',
                'emergency',
            ])],
            'maintenance_title' => ['required', 'string', 'max:150'],
            'maintenance_message' => ['required', 'string', 'max:2000'],
            'maintenance_notes' => ['nullable', 'string', 'max:3000'],
            'maintenance_end_at' => ['nullable', 'date'],
            'system_version' => ['required', 'string', 'max:30'],
        ]);

        $validated['maintenance_enabled'] = $request->boolean('maintenance_enabled');
        $validated['maintenance_end_at'] = $validated['maintenance_end_at'] ?? null;

        return $validated;
    }

    private function resolveAction(array $oldValues, array $newValues): string
    {
        $oldEnabled = (bool) ($oldValues['maintenance_enabled'] ?? false);
        $newEnabled = (bool) ($newValues['maintenance_enabled'] ?? false);

        if (! $oldEnabled && $newEnabled) {
            return 'Mengaktifkan Maintenance Mode';
        }

        if ($oldEnabled && ! $newEnabled) {
            return 'Menyahaktifkan Maintenance Mode';
        }

        return 'Mengemaskini Tetapan Maintenance';
    }

    private function splitNotes(mixed $notes): array
    {
        if (! is_string($notes) || trim($notes) === '') {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $notes) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    private function auditValues(array $values): array
    {
        return [
            'maintenance_enabled' => (bool) ($values['maintenance_enabled'] ?? false),
            'maintenance_type' => $this->auditString($values['maintenance_type'] ?? null),
            'maintenance_title' => $this->auditString($values['maintenance_title'] ?? null),
            'maintenance_end_at' => $this->auditDateTime($values['maintenance_end_at'] ?? null),
            'system_version' => $this->auditString($values['system_version'] ?? null),
        ];
    }

    private function hasAuditChanges(array $oldValues, array $newValues): bool
    {
        return $oldValues !== $newValues;
    }

    private function withSummary(string $action, array $oldValues, array $newValues): array
    {
        $fieldLabels = [
            'maintenance_enabled' => 'Maintenance Enabled',
            'maintenance_type' => 'Maintenance Type',
            'maintenance_title' => 'Maintenance Title',
            'maintenance_end_at' => 'Maintenance End At',
            'system_version' => 'System Version',
        ];

        $changes = [];

        foreach ($fieldLabels as $field => $label) {
            $oldValue = $oldValues[$field] ?? null;
            $newValue = $newValues[$field] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => $this->displayAuditValue($field, $oldValue),
                'new' => $this->displayAuditValue($field, $newValue),
            ];
        }

        return array_merge($newValues, [
            'summary' => [
                'description' => $action,
                'changes' => $changes,
            ],
        ]);
    }

    private function displayAuditValue(string $field, mixed $value): string
    {
        if ($field === 'maintenance_enabled') {
            return $value ? 'Ya' : 'Tidak';
        }

        if ($field === 'maintenance_end_at' && is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('d/m/Y H:i');
            } catch (\Throwable) {
                return $value;
            }
        }

        if ($value === null || $value === '') {
            return 'Tiada';
        }

        return (string) $value;
    }

    private function auditString(mixed $value): ?string
    {
        if (! is_string($value)) {
            if ($value === null) {
                return null;
            }

            $value = (string) $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function auditDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $this->auditString($value);
        }
    }
}
