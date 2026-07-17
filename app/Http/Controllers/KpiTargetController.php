<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\KpiIndicatorSetting;
use App\Models\KpiTarget;
use App\Models\Mill;
use App\Services\KpiEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KpiTargetController extends Controller
{
    public function __construct(private readonly KpiEvaluationService $kpiEvaluationService)
    {
    }

    public function index(Request $request)
    {
        $mills = Mill::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Mill $mill) {
                return [
                    'id' => $mill->id,
                    'code' => $mill->code,
                    'short_code' => $this->toKpiScopeCode($mill->code),
                    'name' => $mill->name,
                    'label' => $this->toKpiScopeCode($mill->code) . ' - ' . $mill->name,
                ];
            });

        if ($mills->isEmpty()) {
            abort(422, 'Tiada kilang aktif untuk tetapan KPI baharu.');
        }

        $firstActiveScope = (string) $mills->first()['id'];
        $validScopes = $mills->pluck('id')->map(fn ($id) => (string) $id)->all();

        $requestedScope = $request->string('scope')->toString();
        $selectedScope = $requestedScope !== '' ? $requestedScope : $firstActiveScope;
        if (! in_array($selectedScope, $validScopes, true)) {
            $selectedScope = $firstActiveScope;
        }

        $selectedYear = (int) ($request->integer('year') ?: now()->year);
        $selectedMillId = (int) $selectedScope;

        $indicatorCatalog = KpiEvaluationService::indicatorCatalog();

        $savedSettings = KpiIndicatorSetting::query()
            ->forScope($selectedMillId)
            ->where('year', $selectedYear)
            ->orderByDesc('id')
            ->get()
            ->keyBy('indicator_code');

        $legacyTargets = KpiTarget::with('mill')
            ->orderByDesc('effective_year')
            ->orderByDesc('id')
            ->get();

        $settingsBySection = [];
        foreach ($indicatorCatalog as $indicator) {
            $setting = $savedSettings->get($indicator['code']);

            $settingsBySection[$indicator['section']][] = [
                'code' => $indicator['code'],
                'name' => $indicator['name'],
                'unit' => $indicator['unit'],
                'direction' => $indicator['direction'],
                'evaluation_basis' => $indicator['evaluation_basis'],
                'supports_monthly_target' => $indicator['supports_monthly_target'],
                'green_threshold' => $setting?->green_threshold,
                'red_threshold' => $setting?->red_threshold,
                'period_target' => $setting?->period_target,
                'monthly_targets' => $setting?->monthly_targets ?? [],
                'is_active' => $setting?->is_active ?? true,
            ];
        }

        $periodInfo = $this->kpiEvaluationService->getPeriodBounds($selectedYear);
        $applicableMonths = $this->kpiEvaluationService->getApplicableMonths($selectedYear);

        return view('kpi.index', [
            'mills' => $mills,
            'selectedScope' => $selectedScope,
            'selectedYear' => $selectedYear,
            'settingsBySection' => $settingsBySection,
            'periodInfo' => $periodInfo,
            'applicableMonths' => $applicableMonths,
            'legacyTargets' => $legacyTargets,
        ]);
    }

    public function store(Request $request)
    {
        $catalogByCode = KpiEvaluationService::indicatorMap();
        $rules = [
            'scope' => ['required'],
            'year' => ['required', 'integer', 'min:2026', 'max:2100'],
            'settings' => ['required', 'array'],
        ];

        foreach (array_keys($catalogByCode) as $code) {
            $rules["settings.{$code}.green_threshold"] = ['nullable', 'numeric', 'min:0'];
            $rules["settings.{$code}.red_threshold"] = ['nullable', 'numeric', 'min:0'];
            $rules["settings.{$code}.period_target"] = ['nullable', 'numeric', 'min:0'];
            $rules["settings.{$code}.is_active"] = ['nullable', 'boolean'];

            for ($m = 1; $m <= 12; $m++) {
                $rules["settings.{$code}.monthly_targets.{$m}.green"] = ['nullable', 'numeric', 'min:0'];
                $rules["settings.{$code}.monthly_targets.{$m}.red"] = ['nullable', 'numeric', 'min:0'];
            }
        }

        $validated = $request->validate($rules, [
            'scope.required' => 'Sila pilih skop kilang.',
            'year.required' => 'Sila pilih tahun KPI.',
            'year.min' => 'Tahun KPI mesti sekurang-kurangnya 2026.',
            'settings.required' => 'Tiada data indikator dihantar.',
            'settings.*.green_threshold.numeric' => 'Nilai ambang hijau mesti dalam bentuk nombor.',
            'settings.*.green_threshold.min' => 'Nilai ambang hijau tidak boleh kurang daripada sifar.',
            'settings.*.red_threshold.numeric' => 'Nilai ambang merah mesti dalam bentuk nombor.',
            'settings.*.red_threshold.min' => 'Nilai ambang merah tidak boleh kurang daripada sifar.',
            'settings.*.period_target.numeric' => 'Nilai sasaran mesti dalam bentuk nombor.',
            'settings.*.period_target.min' => 'Nilai sasaran tidak boleh kurang daripada sifar.',
            'settings.*.monthly_targets.*.green.numeric' => 'Ambang bulanan hijau mesti dalam bentuk nombor.',
            'settings.*.monthly_targets.*.green.min' => 'Ambang bulanan hijau tidak boleh kurang daripada sifar.',
            'settings.*.monthly_targets.*.red.numeric' => 'Ambang bulanan merah mesti dalam bentuk nombor.',
            'settings.*.monthly_targets.*.red.min' => 'Ambang bulanan merah tidak boleh kurang daripada sifar.',
        ]);

        $scope = (string) $validated['scope'];
        $year = (int) $validated['year'];

        if (! ctype_digit($scope) || (int) $scope <= 0) {
            throw ValidationException::withMessages([
                'scope' => 'Skop kilang tidak sah atau tidak aktif.',
            ]);
        }

        $millId = (int) $scope;
        $isValidActiveMill = Mill::where('id', $millId)
            ->where('is_active', true)
            ->exists();

        if (! $isValidActiveMill) {
            throw ValidationException::withMessages([
                'scope' => 'Skop kilang tidak sah atau tidak aktif.',
            ]);
        }

        $inputSettings = $validated['settings'] ?? [];
        $unknownCodes = array_diff(array_keys($inputSettings), array_keys($catalogByCode));
        if (count($unknownCodes) > 0) {
            throw ValidationException::withMessages([
                'settings' => 'Kod indikator tidak sah dikesan dalam permintaan.',
            ]);
        }

        // Phase 1: validate and normalize all indicators, no DB writes.
        $payloads = [];
        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Mac',
            4 => 'April',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Julai',
            8 => 'Ogos',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Disember',
        ];
        $applicableMonths = $this->kpiEvaluationService->getApplicableMonths($year);

        foreach ($catalogByCode as $code => $indicator) {
            $row = $inputSettings[$code] ?? [];
            $isMonthlyFlow = ($indicator['evaluation_basis'] ?? null) === 'monthly_flow';

            $periodTarget = $this->normalizeDecimal($row['period_target'] ?? null);
            $isActive = $request->boolean("settings.{$code}.is_active");

            if ($isMonthlyFlow) {
                $monthlyTargets = [];

                foreach ($applicableMonths as $month) {
                    $monthRow = $row['monthly_targets'][$month] ?? [];
                    $monthGreen = $this->normalizeDecimal($monthRow['green'] ?? null);
                    $monthRed = $this->normalizeDecimal($monthRow['red'] ?? null);

                    $isOneSided = ($monthGreen === null xor $monthRed === null);
                    if ($isOneSided) {
                        throw ValidationException::withMessages([
                            'settings' => $indicator['name'] . ' (' . $monthNames[$month] . '): Ambang hijau dan merah bulanan mesti diisi bersama atau dikosongkan kedua-duanya.',
                        ]);
                    }

                    if ($monthGreen !== null && $monthRed !== null && $monthGreen < $monthRed) {
                        throw ValidationException::withMessages([
                            'settings' => $indicator['name'] . ' (' . $monthNames[$month] . '): Ambang hijau mesti sama atau lebih besar daripada ambang merah.',
                        ]);
                    }

                    $monthlyTargets[(string) $month] = [
                        'green' => $monthGreen,
                        'red' => $monthRed,
                    ];
                }

                $payloads[$code] = [
                    'indicator_code' => $code,
                    'indicator_name' => $indicator['name'],
                    'unit' => $indicator['unit'],
                    'evaluation_direction' => $indicator['direction'],
                    'green_threshold' => null,
                    'red_threshold' => null,
                    'period_target' => $periodTarget,
                    'monthly_targets' => $monthlyTargets,
                    'is_active' => $isActive,
                ];

                continue;
            }

            $green = $this->normalizeDecimal($row['green_threshold'] ?? null);
            $red = $this->normalizeDecimal($row['red_threshold'] ?? null);

            $isOneSided = ($green === null xor $red === null);
            if ($isOneSided) {
                throw ValidationException::withMessages([
                    'settings' => $indicator['name'] . ': Ambang hijau dan merah mesti diisi bersama atau dikosongkan kedua-duanya.',
                ]);
            }

            if ($green !== null && $red !== null) {
                $isOrderValid = $indicator['direction'] === 'higher_is_better'
                    ? $green >= $red
                    : $green <= $red;

                if (! $isOrderValid) {
                    $error = $indicator['direction'] === 'higher_is_better'
                        ? $indicator['name'] . ': Ambang hijau mesti sama atau lebih besar daripada ambang merah.'
                        : $indicator['name'] . ': Ambang hijau mesti sama atau lebih kecil daripada ambang merah.';

                    throw ValidationException::withMessages(['settings' => $error]);
                }
            }

            $payloads[$code] = [
                'indicator_code' => $code,
                'indicator_name' => $indicator['name'],
                'unit' => $indicator['unit'],
                'evaluation_direction' => $indicator['direction'],
                'green_threshold' => $green,
                'red_threshold' => $red,
                'period_target' => $periodTarget,
                'monthly_targets' => [],
                'is_active' => $isActive,
            ];
        }

        // Phase 2: save all payloads atomically.
        try {
            DB::transaction(function () use ($payloads, $millId, $year): void {
                if ($millId <= 0) {
                    throw ValidationException::withMessages([
                        'scope' => 'Skop kilang tidak sah atau tidak aktif.',
                    ]);
                }

                foreach ($payloads as $payload) {
                    $code = $payload['indicator_code'];

                    $existing = KpiIndicatorSetting::query()
                        ->forScope($millId)
                        ->where('year', $year)
                        ->where('indicator_code', $code)
                        ->latest('id')
                        ->first();

                    if ($existing) {
                        $old = $existing->toArray();
                        $existing->update($payload);

                        AuditLog::record('updated', $existing, $old, $existing->toArray());
                        $currentId = $existing->id;
                    } else {
                        $created = KpiIndicatorSetting::create(array_merge($payload, [
                            'mill_id' => $millId,
                            'year' => $year,
                        ]));

                        AuditLog::record('created', $created, null, $created->toArray());
                        $currentId = $created->id;
                    }

                    // Pastikan hanya satu tetapan aktif bagi kombinasi skop/tahun/indikator.
                    if ($payload['is_active']) {
                        KpiIndicatorSetting::query()
                            ->forScope($millId)
                            ->where('year', $year)
                            ->where('indicator_code', $code)
                            ->where('is_active', true)
                            ->where('id', '!=', $currentId)
                            ->update(['is_active' => false]);
                    }
                }
            });
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withErrors(['settings' => 'Gagal menyimpan tetapan KPI. Semua perubahan dibatalkan.'])
                ->withInput();
        }

        return redirect()->route('kpi.index', [
            'scope' => $scope,
            'year' => $year,
        ])->with('success', 'Tetapan KPI berjaya disimpan.');
    }

    private function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'settings' => 'Nilai numerik tidak sah dikesan dalam input.',
            ]);
        }

        return round((float) $value, 2);
    }

    private function toKpiScopeCode(?string $code): string
    {
        return match ($code) {
            'BBJ' => 'KBB',
            'KHG' => 'KKHG',
            default => (string) $code,
        };
    }
}
