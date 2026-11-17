<?php

use App\Models\FfbImportBatch;
use App\Models\Mill;
use App\Models\User;
use App\Services\FfbBatchConfirmationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

$app = require dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $batchId, $actorId, $token, $directory] = $argv + array_fill(0, 6, null);
$connection = DB::connection();
$database = $connection->selectOne('SELECT DATABASE() AS name, VERSION() AS version');
if ($connection->getDriverName() !== 'mysql'
    || $database->name !== 'mpms_ffb_stage3_test'
    || ! str_starts_with($database->version, '8.4.3')) {
    fwrite(STDERR, "Concurrency worker requires mpms_ffb_stage3_test on MySQL 8.4.3.\n");
    exit(2);
}

Carbon::setTestNow('2026-12-15 12:00:00');
$connectionId = (int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
$runtime = static fn (): array => (array) DB::connection()->selectOne(
    'SELECT @@hostname AS host, @@port AS port, @@server_uuid AS server_uuid, '
        .'@@transaction_isolation AS isolation_level, @@autocommit AS autocommit',
);
$write = static function (string $name, array $data) use ($directory): void {
    file_put_contents(
        $directory.DIRECTORY_SEPARATOR.$name,
        json_encode($data, JSON_THROW_ON_ERROR),
        LOCK_EX,
    );
};

try {
    if ($mode === 'winner') {
        DB::transaction(function () use (
            $batchId, $actorId, $token, $connectionId, $runtime, $write, $directory
        ): void {
            $batch = FfbImportBatch::findOrFail((int) $batchId);
            Mill::whereKey($batch->mill_id)->lockForUpdate()->firstOrFail();
            $write('winner-locked.json', [
                'connection_id' => $connectionId,
                'mill_id' => $batch->mill_id,
                'transaction_level' => DB::transactionLevel(),
                'pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(),
                'runtime' => $runtime(),
            ]);
            waitForSignal($directory.DIRECTORY_SEPARATOR.'run-winner');

            $confirmed = app(FfbBatchConfirmationService::class)->confirmCorrection(
                (int) $batchId,
                User::findOrFail((int) $actorId),
                (string) $token,
            );
            $write('winner-prepared.json', [
                'batch_id' => $confirmed->id,
                'transaction_level' => DB::transactionLevel(),
                'pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(),
                'runtime' => $runtime(),
            ]);
            waitForSignal($directory.DIRECTORY_SEPARATOR.'commit-winner');
        });
        $write('winner-result.json', ['status' => 'confirmed', 'connection_id' => $connectionId]);
        exit(0);
    }

    if ($mode === 'loser') {
        $write('loser-attempting.json', [
            'connection_id' => $connectionId,
            'started_at' => microtime(true),
            'runtime' => $runtime(),
        ]);
        try {
            $batch = FfbImportBatch::findOrFail((int) $batchId);
            $confirmed = DB::transaction(function () use (
                $batch, $batchId, $actorId, $token, $write, $runtime
            ): FfbImportBatch {
                Mill::whereKey($batch->mill_id)->lockForUpdate()->firstOrFail();
                $write('loser-locked.json', [
                    'connection_id' => DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id,
                    'mill_id' => $batch->mill_id,
                    'runtime' => $runtime(),
                ]);

                return app(FfbBatchConfirmationService::class)->confirmCorrection(
                    (int) $batchId,
                    User::findOrFail((int) $actorId),
                    (string) $token,
                );
            });
            $write('loser-result.json', [
                'status' => 'confirmed',
                'batch_id' => $confirmed->id,
                'connection_id' => $connectionId,
            ]);
        } catch (ValidationException|AuthorizationException $exception) {
            $write('loser-result.json', [
                'status' => 'rejected',
                'messages' => $exception instanceof ValidationException
                    ? $exception->errors()
                    : [$exception->getMessage()],
                'connection_id' => $connectionId,
                'finished_at' => microtime(true),
            ]);
        }
        exit(0);
    }
} catch (Throwable $exception) {
    $write(($mode === 'winner' ? 'winner' : 'loser').'-error.json', [
        'class' => $exception::class,
        'message' => $exception->getMessage(),
        'connection_id' => $connectionId,
    ]);
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}

fwrite(STDERR, "Unknown concurrency worker mode.\n");
exit(2);

function waitForSignal(string $path): void
{
    $deadline = microtime(true) + 30;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the concurrency test coordinator.');
        }
        usleep(10000);
    }
}
