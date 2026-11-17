<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Artisan;
use App\Services\FfbApprovedMasterInitializer;
use App\Support\MasterUatMigrationPreflight;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('stage3:verify-test-db', function () {
    $connection = DB::connection();
    $details = $connection->selectOne('SELECT VERSION() AS version, DATABASE() AS database_name, CURRENT_USER() AS account');

    if ($connection->getDriverName() !== 'mysql'
        || $details->database_name !== 'mpms_ffb_stage3_test'
        || ! str_starts_with($details->version, '8.4.3')) {
        throw new RuntimeException('Stage 3 commands require the isolated mpms_ffb_stage3_test MySQL 8.4.3 database.');
    }

    $this->info(sprintf(
        'Verified MySQL %s / %s / %s.',
        $details->version,
        $details->database_name,
        $details->account,
    ));
})->purpose('Verify the isolated Stage 3 MySQL test database');

Artisan::command('ffb:initialize-approved-master {--dry-run : Compare the approved plan without inserting masters}', function (
    FfbApprovedMasterInitializer $initializer,
) {
    $result = $initializer->initialize((bool) $this->option('dry-run'));

    $this->info($result['dry_run']
        ? 'Dry run passed. No records were inserted.'
        : 'Approved FFB masters initialized transactionally.');
    $this->table(
        ['Master table', 'Before', 'Planned inserts', $result['dry_run'] ? 'Expected final' : 'After'],
        array_map(
            fn (string $table): array => [
                $table,
                $result['master_counts_before'][$table],
                count($result['plan']['inserts'][$table]),
                $result['master_counts_after'][$table],
            ],
            array_keys($result['master_counts_before']),
        ),
    );
    foreach ($result['plan']['inserts'] as $table => $keys) {
        if ($keys !== []) {
            $this->line($table.' planned: '.implode(', ', $keys));
        }
    }
    $this->line($result['dry_run'] ? 'Operational baseline (no post-write reconciliation in dry-run):' : 'Operational row-count and SHA-256 reconciliation:');
    $this->table(
        ['Operational table', 'Rows before/after', 'SHA-256 before/after'],
        array_map(
            fn (string $table): array => [
                $table,
                $result['operational_before'][$table]['count'].' / '.$result['operational_after'][$table]['count'],
                $result['operational_before'][$table]['sha256'].' / '.$result['operational_after'][$table]['sha256'],
            ],
            array_keys($result['operational_before']),
        ),
    );

    return self::SUCCESS;
})->purpose('Initialize only approved FFB masters in isolated MySQL');

Artisan::command('ffb:verify-master-uat-db', function () {
    $connection = DB::connection();
    if (! app()->environment('master-uat') || DB::getDefaultConnection() !== 'mysql'
        || $connection->getDriverName() !== 'mysql'
        || ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1'], true)
        || $connection->getDatabaseName() !== 'mpms_ffb_master_uat'
        || config('database.connections.mysql.database') !== 'mpms_ffb_master_uat') {
        throw new RuntimeException('Only local MySQL 8.4.3 mpms_ffb_master_uat with APP_ENV=master-uat is allowed.');
    }
    $details = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS version, CURRENT_USER() AS account');
    if ($details->database_name !== 'mpms_ffb_master_uat' || ! str_starts_with($details->version, '8.4.3')) {
        throw new RuntimeException('Only local MySQL 8.4.3 mpms_ffb_master_uat with APP_ENV=master-uat is allowed.');
    }
    $this->info("Verified {$details->database_name} / MySQL {$details->version} / {$details->account}.");
})->purpose('Verify the dedicated master UAT database before setup');

Event::listen(CommandStarting::class, function (CommandStarting $event): void {
    $protectedCommands = [
        'migrate', 'migrate:fresh', 'migrate:install', 'migrate:refresh',
        'migrate:reset', 'migrate:rollback', 'db:seed', 'db:wipe',
    ];

    if (in_array($event->command, $protectedCommands, true)
        && config('database.connections.mysql.database') === 'mpms_ffb_live_test') {
        throw new RuntimeException(
            'Database mutation command blocked for mpms_ffb_live_test. Use the isolated Stage 3 test environment.'
        );
    }

    if (in_array($event->command, $protectedCommands, true)
        && (app()->environment('master-uat') || config('database.connections.mysql.database') === 'mpms_ffb_master_uat')) {
        if ($event->command !== 'migrate') {
            throw new RuntimeException('Destructive migrations and seeders are blocked for master UAT.');
        }
        Artisan::call('ffb:verify-master-uat-db');
        if ($event->input->hasParameterOption(['--database', '--path', '--schema-path', '--seed'])) {
            throw new RuntimeException('Master UAT migration overrides and seeding are blocked.');
        }
        $tables = DB::select(
            'SELECT TABLE_NAME AS table_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );
        if ($tables !== []) {
            $names = array_map(fn (object $table): string => $table->table_name, $tables);
            if (! in_array('migrations', $names, true)) {
                throw new RuntimeException('Master UAT migration requires an empty schema or a verified migration history.');
            }
            $records = DB::table('migrations')
                ->orderBy('id')
                ->get(['id', 'migration', 'batch'])
                ->map(fn (object $record): array => [
                    'id' => $record->id,
                    'migration' => $record->migration,
                    'batch' => $record->batch,
                ])
                ->all();
            $migrationFiles = glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php') ?: [];
            sort($migrationFiles, SORT_STRING);
            $canonicalMigrations = array_map(
                fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
                $migrationFiles,
            );

            $historyState = MasterUatMigrationPreflight::assertMigrationHistory($canonicalMigrations, $records);
            if ($historyState === 'empty') {
                throw new RuntimeException('Master UAT migration table exists without a verified migration history.');
            }
            if ($historyState === 'recovery') {
                $database = DB::connection()->getDatabaseName();
                $operationStatus = DB::selectOne(
                    'SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$database, 'daily_operations', 'operation_status'],
                );
                $ppkColumns = DB::table('information_schema.COLUMNS')
                    ->where('TABLE_SCHEMA', $database)
                    ->where('TABLE_NAME', 'ppks')
                    ->pluck('COLUMN_NAME')
                    ->all();
                $triggers = DB::table('information_schema.TRIGGERS')
                    ->where('TRIGGER_SCHEMA', $database)
                    ->pluck('TRIGGER_NAME')
                    ->all();

                MasterUatMigrationPreflight::assertRecoverySchema([
                    'tables' => $names,
                    'ppk_columns' => $ppkColumns,
                    'operation_status' => $operationStatus === null ? [] : (array) $operationStatus,
                    'triggers' => $triggers,
                ]);
            }
        }
    }
});
