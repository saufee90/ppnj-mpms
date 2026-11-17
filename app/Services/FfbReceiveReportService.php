<?php

namespace App\Services;

use App\Models\FfbImportBatch;
use App\Models\FfbWeighbridgeTicket;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use App\Support\FfbReceivePresentation;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FfbReceiveReportService
{
    public function __construct(
        private readonly FfbDashboardService $dashboard,
        private readonly FfbReportEvidenceService $evidence,
    )
    {
    }

    public function generate(User $user, array $filters, bool $includeTickets = false): array
    {
        if (! in_array($filters['mill'], ['BBJ', 'KHG'], true)) {
            throw ValidationException::withMessages(['mill' => 'Pilih satu kilang yang sah.']);
        }
        $selected = $filters['categories'] ?? ['ppnj_ppk_integrated'];
        if (! is_array($selected) || $selected === [] || array_diff($selected, WeighbridgeCentre::SOURCE_CATEGORIES)) {
            throw ValidationException::withMessages(['categories' => 'Sila pilih sekurang-kurangnya satu kategori BTS yang sah.']);
        }
        $filters['categories'] = array_values(array_intersect(
            ['ppnj_ppk_integrated', 'ppnj_owned', 'external_supplier'], $selected
        ));
        $data = $this->dashboard->generate($user, $filters, $filters['categories']);
        $tickets = [];
        $issues = $mappings = [];
        if ($includeTickets) {
            $batches = FfbImportBatch::whereIn('id', $data['batch_ids'])->get()->keyBy('id');
            $stored = FfbWeighbridgeTicket::whereIn('ffb_import_batch_id', $data['batch_ids'])
                ->whereIn('source_category', $filters['categories'])
                ->orderBy('ticket_date')->orderBy('ticket_number')->orderBy('id')->get();
            $qualities = [];
            foreach ($stored->groupBy('ffb_import_batch_id') as $batchId => $batchTickets) {
                $extracted = $this->evidence->extract($batches[$batchId], $batchTickets);
                foreach ($extracted['tickets'] as $id => $values) {
                    $qualities[$id] = $values;
                }
                array_push($issues, ...$extracted['issues']);
                array_push($mappings, ...$extracted['mappings']);
            }
            foreach ($stored as $ticket) {
                $parent = $ticket->source_category === 'ppnj_ppk_integrated' ? 'ppk' : 'source';
                $tickets[] = [
                    'id' => $ticket->id,
                    'date' => $ticket->ticket_date?->toDateString(),
                    'vehicle' => $ticket->vehicle_number, 'ticket' => $ticket->ticket_number,
                    'do' => $ticket->do_number, 'code' => $ticket->original_source_code,
                    'heading' => $ticket->original_heading, 'weight_mt' => (float) $ticket->weight_mt,
                    'category' => $ticket->source_category,
                    'group_id' => $ticket->source_category.':'.data_get($ticket->resolution_snapshot, $parent.'.id'),
                    'group_name' => data_get($ticket->resolution_snapshot, $parent.'.name'),
                    'quality' => $qualities[$ticket->id],
                ];
            }
            usort($tickets, fn ($a, $b) =>
                (array_search($a['category'], $filters['categories'], true) <=> array_search($b['category'], $filters['categories'], true))
                ?: strcmp($a['group_name'], $b['group_name'])
                ?: strcmp($a['group_id'], $b['group_id'])
                ?: strcmp($a['date'] ?? '', $b['date'] ?? '')
                ?: strcmp($a['ticket'], $b['ticket'])
                ?: ($a['id'] <=> $b['id']));
        }

        return [
            'type' => 'hq', 'filters' => $filters, 'data' => $data, 'tickets' => $tickets,
            'title' => 'Laporan Penerimaan BTS',
            'parallel_run_notice' => $filters['start'] <= FfbDailyReceiptService::PARALLEL_RUN_END
                && $filters['end'] >= FfbDailyReceiptService::PARALLEL_RUN_START,
            'mill_name' => $filters['mill'] === 'BBJ'
                ? 'Kilang Sawit Bukit Bujang (KBB)' : 'Kilang Sawit Kahang (KHG)',
            'generated_at' => CarbonImmutable::now('Asia/Kuala_Lumpur')->format('d/m/Y H:i:s').' MYT',
            'category_label' => implode(' + ', array_map(fn ($category) => FfbReceivePresentation::category($category), $filters['categories'])),
            'quality_issues' => array_values(array_unique($issues)),
            'quality_mappings' => array_values(array_unique($mappings)),
        ];
    }
}
