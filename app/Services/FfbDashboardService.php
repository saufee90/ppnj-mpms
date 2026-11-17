<?php

namespace App\Services;

use App\Models\FfbImportBatch;
use App\Models\FfbWeighbridgeTicket;
use App\Models\Mill;
use App\Models\Ppk;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use App\Support\FfbReceivePresentation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class FfbDashboardService
{
    public function mills(User $user): Collection
    {
        Gate::forUser($user)->authorize('viewAny', FfbImportBatch::class);

        return Mill::whereIn('code', ['BBJ', 'KHG'])
            ->when($user->isMillScopedRole(), fn ($query) => $query->whereKey($user->mill_id))
            ->orderBy('code')->get();
    }

    public function batches(User $user, string $mill): Builder
    {
        $mills = $this->mills($user);
        abort_unless($mill === 'all' ? $user->canViewFfbReceiveReports() : $mills->contains('code', $mill), 403);

        return FfbImportBatch::where('status', 'confirmed')->where('active_slot', 1)
            ->whereIn('mill_id', $mills->pluck('id'))
            ->when($mill !== 'all', fn ($query) => $query->where('mill_id', $mills->firstWhere('code', $mill)->id));
    }

    public function latestDate(User $user, string $mill): ?string
    {
        $latest = $this->batches($user, $mill)->max('receipt_date');

        return $latest ? CarbonImmutable::parse($latest)->toDateString() : null;
    }

    public function generate(User $user, array $filters, ?array $selectedCategories = null): array
    {
        $query = $this->batches($user, $filters['mill'])
            ->whereDate('receipt_date', '>=', $filters['start'])
            ->whereDate('receipt_date', '<=', $filters['end']);
        $batches = $query->orderBy('receipt_date')->orderBy('id')
            ->get(['id', 'mill_id', 'receipt_date', 'receipt_type', 'ticket_count', 'parsed_total_mt']);
        $mills = $this->mills($user)->when($filters['mill'] !== 'all',
            fn ($rows) => $rows->where('code', $filters['mill']));
        $millTotals = [];
        foreach ($mills as $mill) {
            $millTotals[$mill->id] = [
                'name' => $mill->code === 'BBJ' ? 'KBB' : 'Kahang',
                'micros' => 0, 'tickets' => 0, 'zero_days' => 0, 'confirmed_days' => 0,
            ];
        }
        $categories = [];
        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $category) {
            $categories[$category] = ['name' => FfbReceivePresentation::category($category), 'micros' => 0, 'tickets' => 0];
        }
        $trend = [];
        $zeroDates = [];
        $batchTotals = [];
        foreach ($batches as $batch) {
            $date = $batch->receipt_date->toDateString();
            $trend[$date] ??= [
                'date' => $date, 'micros' => 0, 'tickets' => 0, 'zero_days' => 0,
                'categories' => array_fill_keys(WeighbridgeCentre::SOURCE_CATEGORIES, 0),
            ];
            $millTotals[$batch->mill_id]['confirmed_days']++;
            $batchTotals[$batch->id] = ['micros' => 0, 'tickets' => 0];
            if ($batch->receipt_type === 'zero_receipt') {
                $zeroDates[$date] = true;
                $trend[$date]['zero_days']++;
                $millTotals[$batch->mill_id]['zero_days']++;
            }
        }

        $entities = [];
        $totalMicros = 0;
        $ticketCount = 0;
        // Join only the batch: joining aliases/mappings would multiply ticket weights.
        $tickets = FfbWeighbridgeTicket::query()
            ->join('ffb_import_batches as batch', 'batch.id', '=', 'ffb_weighbridge_tickets.ffb_import_batch_id')
            ->whereIn('batch.id', $batches->pluck('id'))
            ->select([
                'ffb_weighbridge_tickets.id', 'ffb_weighbridge_tickets.ffb_import_batch_id',
                'ffb_weighbridge_tickets.ppk_id', 'ffb_weighbridge_tickets.weighbridge_centre_id',
                'ffb_weighbridge_tickets.source_category', 'ffb_weighbridge_tickets.resolution_snapshot',
                'ffb_weighbridge_tickets.weight_mt', 'batch.mill_id as receipt_mill_id',
                'batch.receipt_date as receipt_day', 'batch.receipt_type as batch_receipt_type',
            ])
            ->withCasts(['receipt_day' => 'date'])
            ->orderBy('ffb_weighbridge_tickets.id')->cursor();
        foreach ($tickets as $ticket) {
            $category = $ticket->source_category;
            $integrated = $category === 'ppnj_ppk_integrated';
            $id = $integrated ? $ticket->ppk_id : $ticket->weighbridge_centre_id;
            $snapshotKey = $integrated ? 'ppk' : 'source';
            $name = data_get($ticket->resolution_snapshot, $snapshotKey.'.name');
            if ($ticket->batch_receipt_type !== 'excel' || ! isset($categories[$category]) || ! $id
                || ! is_string($name) || trim($name) === ''
                || (int) data_get($ticket->resolution_snapshot, $snapshotKey.'.id') !== (int) $id) {
                throw new RuntimeException("Integriti identiti tiket FFB #{$ticket->id} tidak sah. Dashboard tidak boleh mengagregat data ini.");
            }
            $micros = $this->micros($ticket->weight_mt);
            $batchTotals[$ticket->ffb_import_batch_id]['micros'] += $micros;
            $batchTotals[$ticket->ffb_import_batch_id]['tickets']++;
            if ($selectedCategories !== null && ! in_array($category, $selectedCategories, true)) {
                continue;
            }
            $key = $category.':'.$id;
            $entities[$key] ??= [
                'id' => $key, 'name' => $name, 'category' => $category,
                'micros' => 0, 'tickets' => 0,
            ];
            $entities[$key]['micros'] += $micros;
            $entities[$key]['tickets']++;
            $categories[$category]['micros'] += $micros;
            $categories[$category]['tickets']++;
            $millTotals[$ticket->receipt_mill_id]['micros'] += $micros;
            $millTotals[$ticket->receipt_mill_id]['tickets']++;
            $day = $ticket->receipt_day->toDateString();
            $trend[$day]['micros'] += $micros;
            $trend[$day]['tickets']++;
            $trend[$day]['categories'][$category] += $micros;
            $totalMicros += $micros;
            $ticketCount++;
        }
        foreach ($batches as $batch) {
            $actual = $batchTotals[$batch->id];
            if ($actual['tickets'] !== $batch->ticket_count || $actual['micros'] !== $this->micros($batch->parsed_total_mt)) {
                throw new RuntimeException("Rekonsiliasi batch FFB #{$batch->id} gagal. Jumlah tiket dan batch tidak sepadan.");
            }
        }
        $ranked = collect($entities)->sort(function (array $a, array $b): int {
            return ($b['micros'] <=> $a['micros']) ?: strcmp($a['id'], $b['id']);
        })->map(fn ($entity) => $this->present($entity, $totalMicros))->values();
        $integratedPpkIds = $ranked->where('category', 'ppnj_ppk_integrated')
            ->map(fn (array $entity): int => (int) substr($entity['id'], strlen('ppnj_ppk_integrated:')))
            ->all();
        $officialPpkKeys = Ppk::official()->whereIn('id', $integratedPpkIds)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => ['ppnj_ppk_integrated:'.$id => true])
            ->all();
        $integrated = $ranked->where('category', 'ppnj_ppk_integrated');
        $rankings = ['overall' => $ranked->take(5)->values()->all()];
        foreach (WeighbridgeCentre::SOURCE_CATEGORIES as $category) {
            $rankings[$category] = $category === 'ppnj_ppk_integrated'
                ? $integrated->filter(fn (array $entity): bool => isset($officialPpkKeys[$entity['id']]))
                    ->take(5)->values()->all()
                : $ranked->where('category', $category)->take(5)->values()->all();
        }
        $rankings['independent_integrated_estate'] = $integrated
            ->reject(fn (array $entity): bool => isset($officialPpkKeys[$entity['id']]))
            ->take(5)->values()->all();

        return [
            'total_mt' => $totalMicros / 1000000.0, 'ticket_count' => $ticketCount,
            'confirmed_days' => $batches->count(), 'zero_mill_days' => array_sum(array_column($millTotals, 'zero_days')),
            'zero_dates' => count($zeroDates), 'has_data' => $batches->isNotEmpty(),
            'categories' => array_map(fn ($row) => $this->present($row, $totalMicros),
                $selectedCategories === null ? $categories : array_intersect_key($categories, array_flip($selectedCategories))),
            'mills' => array_values(array_map(fn ($row) => $this->present($row, $totalMicros), $millTotals)),
            'trend' => array_values(array_map(fn ($row) => $this->present($row, $totalMicros), $trend)),
            'rankings' => $rankings,
            'sources' => $ranked->all(),
            'batch_ids' => $batches->modelKeys(),
        ];
    }

    public function period(array $input, string $latest): array
    {
        $period = $input['period'] ?? 'daily';
        $date = CarbonImmutable::parse($input['date'] ?? $latest, 'Asia/Kuala_Lumpur');
        [$start, $end] = match ($period) {
            'monthly' => [
                CarbonImmutable::parse(($input['month'] ?? substr($latest, 0, 7)).'-01', 'Asia/Kuala_Lumpur')->startOfMonth(),
                CarbonImmutable::parse(($input['month'] ?? substr($latest, 0, 7)).'-01', 'Asia/Kuala_Lumpur')->endOfMonth(),
            ],
            'yearly' => [
                $date->setDate((int) ($input['year'] ?? substr($latest, 0, 4)), 1, 1),
                $date->setDate((int) ($input['year'] ?? substr($latest, 0, 4)), 12, 31),
            ],
            'range' => [CarbonImmutable::parse($input['start'], 'Asia/Kuala_Lumpur'), CarbonImmutable::parse($input['end'], 'Asia/Kuala_Lumpur')],
            default => [$date, $date],
        };

        return [
            'mill' => $input['mill'], 'period' => $period,
            'date' => $date->toDateString(), 'month' => $input['month'] ?? substr($latest, 0, 7),
            'year' => $input['year'] ?? substr($latest, 0, 4),
            'start' => $start->toDateString(), 'end' => $end->toDateString(),
        ];
    }

    private function present(array $row, int $total): array
    {
        $row['mt'] = $row['micros'] / 1000000.0;
        $row['percentage'] = $total > 0 ? $row['micros'] / $total * 100 : 0;
        if (isset($row['categories'])) {
            $row['categories'] = array_map(fn (int $micros) => $micros / 1000000.0, $row['categories']);
        }
        unset($row['micros']);

        return $row;
    }

    private function micros(?string $value): int
    {
        if ($value === null || ! preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/D', $value, $match)) {
            throw new RuntimeException('Berat tiket atau jumlah batch FFB disahkan tidak sah.');
        }

        return (int) $match[1] * 1000000 + (int) str_pad($match[2] ?? '', 6, '0');
    }
}
