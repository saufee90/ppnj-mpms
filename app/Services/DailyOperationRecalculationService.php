<?php

namespace App\Services;

use App\Models\DailyOperation;
use App\Models\Mill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyOperationRecalculationService
{
    public function prepareForPersistence(array $data, ?int $ignoreId = null): array
    {
        $data = $this->applyOperationStatusRules($data);
        $data = $this->applyOpeningBalance($data, $ignoreId);

        $operationStatus = $data['operation_status'] ?? 'Operasi';
        if ($operationStatus !== 'Operasi') {
            $data = $this->applyTidakOperasiFields($data);
        } else {
            $data = $this->applyOperasiDerivedFields($data);
        }

        $data = $this->applyThroughputAndUtilisation($data);

        return $data;
    }

    public function resolveOpeningBalance(?int $millId, ?string $tarikh, ?int $ignoreId = null): array
    {
        if (! $tarikh) {
            return [
                'can_edit_baki_bts_semalam' => true,
                'can_edit_stok_cpo_yesterday' => true,
                'can_edit_stok_pk_yesterday' => true,
                'require_manual_stok_cpo_yesterday' => false,
                'require_manual_stok_pk_yesterday' => false,
                'baki_bts_semalam' => 0.0,
                'stok_cpo_yesterday' => null,
                'stok_pk_yesterday' => null,
            ];
        }

        $selectedDate = Carbon::parse($tarikh);
        $isFirstDayOfMonth = $selectedDate->day === 1;

        if (! $millId) {
            return [
                'can_edit_baki_bts_semalam' => ! $isFirstDayOfMonth,
                'can_edit_stok_cpo_yesterday' => $isFirstDayOfMonth,
                'can_edit_stok_pk_yesterday' => $isFirstDayOfMonth,
                'require_manual_stok_cpo_yesterday' => $isFirstDayOfMonth,
                'require_manual_stok_pk_yesterday' => $isFirstDayOfMonth,
                'baki_bts_semalam' => 0.0,
                'stok_cpo_yesterday' => $isFirstDayOfMonth ? null : 0.0,
                'stok_pk_yesterday' => $isFirstDayOfMonth ? null : 0.0,
            ];
        }

        $previousQuery = DailyOperation::where('mill_id', $millId)
            ->where('tarikh', '<', $selectedDate->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id');

        $previousMonthBakiQuery = DailyOperation::where('mill_id', $millId)
            ->whereYear('tarikh', $selectedDate->year)
            ->whereMonth('tarikh', $selectedDate->month)
            ->where('tarikh', '<', $selectedDate->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id');

        if ($ignoreId) {
            $previousQuery->where('id', '!=', $ignoreId);
            $previousMonthBakiQuery->where('id', '!=', $ignoreId);
        }

        $previous = $previousQuery->first();
        $previousMonthBaki = $previousMonthBakiQuery->first();

        $bakiBtsSemalam = $isFirstDayOfMonth
            ? 0.0
            : round((float) ($previousMonthBaki?->baki_bts_selepas_diproses ?? 0), 2);

        if ($isFirstDayOfMonth) {
            return [
                'can_edit_baki_bts_semalam' => false,
                'can_edit_stok_cpo_yesterday' => true,
                'can_edit_stok_pk_yesterday' => true,
                'require_manual_stok_cpo_yesterday' => true,
                'require_manual_stok_pk_yesterday' => true,
                'baki_bts_semalam' => $bakiBtsSemalam,
                'stok_cpo_yesterday' => null,
                'stok_pk_yesterday' => null,
            ];
        }

        return [
            'can_edit_baki_bts_semalam' => false,
            'can_edit_stok_cpo_yesterday' => false,
            'can_edit_stok_pk_yesterday' => false,
            'require_manual_stok_cpo_yesterday' => false,
            'require_manual_stok_pk_yesterday' => false,
            'baki_bts_semalam' => $bakiBtsSemalam,
            'stok_cpo_yesterday' => round((float) ($previous?->stok_cpo ?? 0), 2),
            'stok_pk_yesterday' => round((float) ($previous?->stok_pk ?? 0), 2),
        ];
    }

    public function recalculateFromDate(int $millId, Carbon $operationDate): int
    {
        return DB::transaction(function () use ($millId, $operationDate): int {
            return $this->recalculateFromDateWithinTransaction($millId, $operationDate);
        });
    }

    private function recalculateFromDateWithinTransaction(int $millId, Carbon $operationDate): int
    {
        $startDate = $operationDate->copy()->startOfDay();

        $amendedRecordExists = DailyOperation::query()
            ->where('mill_id', $millId)
            ->whereDate('tarikh', $startDate->toDateString())
            ->exists();

        if (! $amendedRecordExists) {
            return 0;
        }

        $rows = DailyOperation::query()
            ->where('mill_id', $millId)
            ->whereDate('tarikh', '>=', $startDate->toDateString())
            ->orderBy('tarikh')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $previousRecord = DailyOperation::query()
            ->where('mill_id', $millId)
            ->whereDate('tarikh', '<', $startDate->toDateString())
            ->orderByDesc('tarikh')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $currentDate = null;
        $previousDayBaseline = $previousRecord;
        $latestProcessedRecord = $previousRecord;

        foreach ($rows as $row) {
            $rowDate = $row->tarikh->toDateString();
            if ($currentDate !== $rowDate) {
                $currentDate = $rowDate;
                $previousDayBaseline = $latestProcessedRecord;
            }

            $this->recalculateRow($row, $previousDayBaseline);
            $latestProcessedRecord = $row;
        }

        $this->refreshOperationalCaches($millId);

        return max($rows->count() - 1, 0);
    }

    private function recalculateRow(DailyOperation $row, ?DailyOperation $previousDay): void
    {
        $isFirstDayOfMonth = $row->tarikh->day === 1;

        if ($isFirstDayOfMonth) {
            $row->baki_bts_semalam = 0.0;
            $row->stok_cpo_yesterday = round((float) ($row->stok_cpo_yesterday ?? 0), 2);
            $row->stok_pk_yesterday = round((float) ($row->stok_pk_yesterday ?? 0), 2);
        } else {
            $row->baki_bts_semalam = $previousDay
                ? round((float) ($previousDay->baki_bts_selepas_diproses ?? 0), 2)
                : round((float) ($row->baki_bts_semalam ?? 0), 2);
            $row->stok_cpo_yesterday = $previousDay
                ? round((float) ($previousDay->stok_cpo ?? 0), 2)
                : round((float) ($row->stok_cpo_yesterday ?? 0), 2);
            $row->stok_pk_yesterday = $previousDay
                ? round((float) ($previousDay->stok_pk ?? 0), 2)
                : round((float) ($row->stok_pk_yesterday ?? 0), 2);
        }

        if (($row->operation_status ?? 'Operasi') !== 'Operasi') {
            $previousBakiBts = $previousDay
                ? round((float) ($previousDay->baki_bts_selepas_diproses ?? 0), 2)
                : round((float) ($row->baki_bts_semalam ?? 0), 2);
            $previousStokCpo = $previousDay
                ? round((float) ($previousDay->stok_cpo ?? 0), 2)
                : round((float) ($row->stok_cpo_yesterday ?? 0), 2);
            $previousStokPk = $previousDay
                ? round((float) ($previousDay->stok_pk ?? 0), 2)
                : round((float) ($row->stok_pk_yesterday ?? 0), 2);

            $row->bts_diproses = 0.0;
            $row->jam_operasi = 0.0;
            $row->downtime_jam = 0.0;

            $row->baki_bts_selepas_diproses = round(
                $previousBakiBts + (float) ($row->bts_diterima ?? 0),
                2
            );
            $row->stok_cpo = $previousStokCpo;
            $row->stok_pk = $previousStokPk;

            $row->produksi_cpo = 0.0;
            $row->produksi_pk = 0.0;
            $row->oer = 0.0;
            $row->ker = 0.0;
            $row->throughput = 0.0;
            $row->utilisation_rate = 0.0;

            $row->save();

            return;
        }

        $row->baki_bts_selepas_diproses = round(
            (float) ($row->baki_bts_semalam ?? 0)
                + (float) ($row->bts_diterima ?? 0)
                - (float) ($row->bts_diproses ?? 0),
            2
        );

        $this->assertNonNegativeBaki((float) $row->baki_bts_selepas_diproses);

        $row->produksi_cpo = $this->calculateProductionFromRecord($row, 'stok_cpo');
        $row->produksi_pk = $this->calculateProductionFromRecord($row, 'stok_pk');

        $this->assertNonNegativeProduction([
            'produksi_cpo' => $row->produksi_cpo,
            'produksi_pk' => $row->produksi_pk,
        ]);

        $row->oer = $this->calculateOer([
            'bts_diproses' => $row->bts_diproses,
            'produksi_cpo' => $row->produksi_cpo,
        ]);
        $row->ker = $this->calculateKer([
            'bts_diproses' => $row->bts_diproses,
            'produksi_pk' => $row->produksi_pk,
        ]);

        $row->throughput = $row->computeThroughput();
        $row->utilisation_rate = $row->computeUtilisationRate((float) $row->throughput);

        $row->save();
    }

    private function applyOperationStatusRules(array $data): array
    {
        if (($data['operation_status'] ?? 'Operasi') === 'Operasi') {
            return $data;
        }

        $data['bts_diproses'] = 0.0;
        $data['jam_operasi'] = 0.0;
        $data['downtime_jam'] = 0.0;

        return $data;
    }

    private function applyOpeningBalance(array $data, ?int $ignoreId = null): array
    {
        $opening = $this->resolveOpeningBalance((int) $data['mill_id'], (string) $data['tarikh'], $ignoreId);

        if ($opening['can_edit_baki_bts_semalam']) {
            $data['baki_bts_semalam'] = round((float) ($data['baki_bts_semalam'] ?? 0), 2);
        } else {
            $data['baki_bts_semalam'] = $opening['baki_bts_semalam'];
        }

        if ($opening['can_edit_stok_cpo_yesterday']) {
            if ($opening['require_manual_stok_cpo_yesterday'] && $data['stok_cpo_yesterday'] === null) {
                throw ValidationException::withMessages([
                    'stok_cpo_yesterday' => 'Stok CPO Semalam wajib diisi pada hari pertama bulan.',
                ]);
            }

            $data['stok_cpo_yesterday'] = round((float) ($data['stok_cpo_yesterday'] ?? 0), 2);
        } else {
            $data['stok_cpo_yesterday'] = $opening['stok_cpo_yesterday'];
        }

        if ($opening['can_edit_stok_pk_yesterday']) {
            if ($opening['require_manual_stok_pk_yesterday'] && $data['stok_pk_yesterday'] === null) {
                throw ValidationException::withMessages([
                    'stok_pk_yesterday' => 'Stok PK Semalam wajib diisi pada hari pertama bulan.',
                ]);
            }

            $data['stok_pk_yesterday'] = round((float) ($data['stok_pk_yesterday'] ?? 0), 2);
        } else {
            $data['stok_pk_yesterday'] = $opening['stok_pk_yesterday'];
        }

        return $data;
    }

    private function applyOperasiDerivedFields(array $data): array
    {
        $data['baki_bts_selepas_diproses'] = round(
            (float) ($data['baki_bts_semalam'] ?? 0)
                + (float) ($data['bts_diterima'] ?? 0)
                - (float) ($data['bts_diproses'] ?? 0),
            2
        );
        $this->assertNonNegativeBaki((float) $data['baki_bts_selepas_diproses']);

        $data['produksi_cpo'] = $this->calculateProduction($data, 'stok_cpo');
        $data['produksi_pk'] = $this->calculateProduction($data, 'stok_pk');
        $this->assertNonNegativeProduction($data);

        $data['oer'] = $this->calculateOer($data);
        $data['ker'] = $this->calculateKer($data);

        return $data;
    }

    private function applyTidakOperasiFields(array $data): array
    {
        $data['produksi_cpo'] = 0.0;
        $data['produksi_pk'] = 0.0;
        $data['oer'] = 0.0;
        $data['ker'] = 0.0;
        $data['ffa'] = 0.0;
        $data['moisture'] = 0.0;
        $data['dirt'] = 0.0;

        // Pada tidak operasi, BTS diterima ditambah tetapi stok produk kekal.
        $data['baki_bts_selepas_diproses'] = round(
            (float) ($data['baki_bts_semalam'] ?? 0)
                + (float) ($data['bts_diterima'] ?? 0),
            2
        );
        $data['stok_cpo'] = round((float) ($data['stok_cpo_yesterday'] ?? 0), 2);
        $data['stok_pk'] = round((float) ($data['stok_pk_yesterday'] ?? 0), 2);

        return $data;
    }

    private function applyThroughputAndUtilisation(array $data): array
    {
        $operation = new DailyOperation($data);
        $operation->mill()->associate(new Mill([
            'id' => (int) ($data['mill_id'] ?? 0),
            'code' => $this->resolveMillCode((int) ($data['mill_id'] ?? 0)),
        ]));

        if (($data['operation_status'] ?? 'Operasi') !== 'Operasi') {
            $data['throughput'] = 0.0;
            $data['utilisation_rate'] = 0.0;

            return $data;
        }

        $throughput = $operation->computeThroughput();
        $data['throughput'] = $throughput;
        $data['utilisation_rate'] = $operation->computeUtilisationRate((float) $throughput);

        return $data;
    }

    private function calculateProductionFromRecord(DailyOperation $row, string $field): float
    {
        $data = [
            'mill_id' => $row->mill_id,
            'stok_cpo' => $row->stok_cpo,
            'stok_pk' => $row->stok_pk,
            'stok_cpo_yesterday' => $row->stok_cpo_yesterday,
            'stok_pk_yesterday' => $row->stok_pk_yesterday,
            'pengeluaran_cpo' => $row->pengeluaran_cpo,
            'pengeluaran_pk' => $row->pengeluaran_pk,
            'pk_kcp_to_hopper' => $row->pk_kcp_to_hopper,
        ];

        return $this->calculateProduction($data, $field);
    }

    private function calculateProduction(array $data, string $field): float
    {
        $stok = (float) ($data[$field] ?? 0);
        $yesterday = (float) ($data["{$field}_yesterday"] ?? 0);
        $salesField = $field === 'stok_cpo' ? 'pengeluaran_cpo' : 'pengeluaran_pk';
        $sales = (float) ($data[$salesField] ?? 0);

        if ($field === 'stok_pk' && $this->isBukitBujangMillId((int) ($data['mill_id'] ?? 0))) {
            $hopper = (float) ($data['pk_kcp_to_hopper'] ?? 0);

            return round($stok - $yesterday + $sales + $hopper, 2);
        }

        return round($stok - $yesterday + $sales, 2);
    }

    private function calculateOer(array $data): float
    {
        $btsDiproses = (float) ($data['bts_diproses'] ?? 0);
        $produksiCpo = (float) ($data['produksi_cpo'] ?? 0);

        if ($btsDiproses <= 0) {
            return 0.0;
        }

        return round(($produksiCpo / $btsDiproses) * 100, 2);
    }

    private function calculateKer(array $data): float
    {
        $btsDiproses = (float) ($data['bts_diproses'] ?? 0);
        $produksiPk = (float) ($data['produksi_pk'] ?? 0);

        if ($btsDiproses <= 0) {
            return 0.0;
        }

        return round(($produksiPk / $btsDiproses) * 100, 2);
    }

    private function assertNonNegativeBaki(float $bakiBtsSelepasDiproses): void
    {
        if ($bakiBtsSelepasDiproses < 0) {
            throw ValidationException::withMessages([
                'bts_diproses' => 'Baki BTS Selepas Diproses tidak boleh negatif. Sila semak BTS Diproses.',
            ]);
        }
    }

    private function assertNonNegativeProduction(array $data): void
    {
        $produksiCpo = (float) ($data['produksi_cpo'] ?? 0);
        $produksiPk = (float) ($data['produksi_pk'] ?? 0);

        if ($produksiCpo < 0 || $produksiPk < 0) {
            throw ValidationException::withMessages([
                'produksi_cpo' => 'Pengeluaran CPO/PK tidak boleh negatif. Sila semak Stok Semalam, Stok Hari Ini dan Jualan.',
                'produksi_pk' => 'Pengeluaran CPO/PK tidak boleh negatif. Sila semak Stok Semalam, Stok Hari Ini dan Jualan.',
            ]);
        }
    }

    private function isBukitBujangMillId(?int $millId): bool
    {
        if (! $millId) {
            return false;
        }

        return $this->resolveMillCode($millId) === 'BBJ';
    }

    private function resolveMillCode(int $millId): ?string
    {
        if ($millId <= 0) {
            return null;
        }

        return Mill::whereKey($millId)->value('code');
    }

    private function refreshOperationalCaches(int $millId): void
    {
        // Sistem semasa baca terus dari DB. Jika cache bertag diperkenalkan kemudian,
        // invalidasi ini akan memastikan dashboard/laporan terus segar selepas amend.
        try {
            Cache::tags([
                'daily-operations',
                'daily-operations:mill:' . $millId,
                'dashboard',
                'reports',
            ])->flush();
        } catch (\Throwable) {
            // Abaikan jika store cache tidak menyokong tag.
        }
    }
}
