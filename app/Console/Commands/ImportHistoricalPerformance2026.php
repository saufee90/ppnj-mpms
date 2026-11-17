<?php

namespace App\Console\Commands;

use App\Models\MillMonthlyHistory;
use App\Services\HistoricalPerformanceImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportHistoricalPerformance2026 extends Command
{
    protected $signature = 'mps:import-history-2026 {--dry-run : Validate without inserting records}';

    protected $description = 'Import the verified Jan-Jun 2026 monthly performance into the isolated local snapshot';

    public function handle(HistoricalPerformanceImportService $importer): int
    {
        $connection = DB::connection();
        if (! app()->environment('local')
            || $connection->getDriverName() !== 'mysql'
            || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1'], true)
            || $connection->getDatabaseName() !== 'mpms_ffb_live_test'
            || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'mpms_ffb_live_test') {
            $this->error('Stopped: this command requires local MySQL database mpms_ffb_live_test.');

            return self::FAILURE;
        }

        try {
            $result = $importer->import((bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'DRY RUN - would insert: ' : 'Inserted: ')
            .$result['inserted'].'; unchanged: '.$result['unchanged']);
        $totals = $this->option('dry-run')
            ? collect(HistoricalPerformanceImportService::EXPECTED_TOTALS)
                ->map(fn (array $totals, string $code) => [$code, ...$totals])->all()
            : MillMonthlyHistory::query()->with('mill')
                ->where('year', 2026)->whereBetween('month', [1, 6])->get()
                ->groupBy('mill_id')->map(fn ($rows) => [
                    $rows->first()->mill->code,
                    ...array_map(fn (string $field) => number_format((float) $rows->sum($field), 2, '.', ''), MillMonthlyHistory::FIELDS),
                ])->values()->all();
        $this->line($this->option('dry-run') ? 'Verified source totals:' : 'Persisted Jan-Jun totals:');
        $this->table(
            ['Mill', ...MillMonthlyHistory::FIELDS],
            $totals
        );

        return self::SUCCESS;
    }
}
