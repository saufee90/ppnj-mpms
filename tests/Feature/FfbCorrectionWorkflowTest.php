<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DailyOperation;
use App\Models\FfbImportBatch;
use App\Models\FfbSourceGroup;
use App\Models\Mill;
use App\Models\Ppk;
use App\Models\Role;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use App\Services\FfbBatchConfirmationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Str;
use Tests\TestCase;

class FfbCorrectionWorkflowTest extends TestCase
{
    private Mill $kbb;
    private Mill $kahang;
    private User $pegawaiKbb;
    private User $pegawaiKahang;
    private User $admin;
    private User $marketing;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (app()->environment('master-uat')) {
        $this->withoutMiddleware(
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class
    );
}
        $connection = DB::connection();
        $details = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS version');
        $this->assertSame('mysql', $connection->getDriverName());
        $targets = ['stage3-test-app' => 'mpms_ffb_stage3_test', 'master-uat' => 'mpms_ffb_master_uat'];
        $this->assertArrayHasKey(app()->environment(), $targets);
        $this->assertSame($targets[app()->environment()], $details->database_name);
        $this->assertContains($connection->getConfig('host'), ['localhost', '127.0.0.1']);
        $this->assertStringStartsWith('8.4.3', $details->version);

        Carbon::setTestNow('2026-12-15 12:00:00');
        DB::beginTransaction();
        Storage::fake('ffb_receive');
        Storage::fake('public');

        $this->kbb = Mill::create([
            'code' => 'S3K'.Str::upper(Str::random(7)),
            'name' => 'KBB Stage3 '.Str::upper(Str::random(5)),
            'location' => 'Johor', 'is_active' => true,
        ]);
        $this->kahang = Mill::create([
            'code' => 'S3H'.Str::upper(Str::random(7)),
            'name' => 'Kahang Stage3 '.Str::upper(Str::random(5)),
            'location' => 'Johor', 'is_active' => true,
        ]);
        $this->pegawaiKbb = $this->user(Role::PEGAWAI_KILANG, $this->kbb, 'pegawai-kbb');
        $this->pegawaiKahang = $this->user(Role::PEGAWAI_KILANG, $this->kahang, 'pegawai-kahang');
        $this->admin = $this->user(Role::ADMIN, null, 'admin');
        $this->marketing = $this->user(Role::EKSEKUTIF_PEMASARAN_BTS, null, 'marketing');
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
            Carbon::setTestNow();
        }

        parent::tearDown();
    }

    public function test_november_zero_correction_preserves_versions_and_never_touches_daily_operations(): void
    {
        $predecessor = $this->confirmedZero($this->kbb, $this->pegawaiKbb, '2026-11-10');
        $original = $predecessor->fresh()->getAttributes();

        $this->actingAs($this->pegawaiKbb)
            ->get(route('ffb-receive.correction.create', $predecessor))
            ->assertOk()->assertSee('Buat Pembetulan');
        $response = $this->actingAs($this->pegawaiKbb)->post(
            route('ffb-receive.correction.store', $predecessor),
            ['receipt_type' => 'zero_receipt', 'correction_reason' => 'Pelarasan timbang latihan'],
        )->assertRedirect();
        $candidate = FfbImportBatch::where('supersedes_batch_id', $predecessor->id)->firstOrFail();
        $summary = app(FfbBatchConfirmationService::class)->summaryCorrection($candidate);

        $this->actingAs($this->pegawaiKbb)
            ->get(route('ffb-receive.correction.confirmation', $candidate))
            ->assertOk()->assertSee('PARALLEL RUN')->assertSee('Perbandingan Sebelum dan Selepas');
        $this->actingAs($this->pegawaiKbb)->post(
            route('ffb-receive.correction.confirm', $candidate),
            ['acknowledgement' => '1', 'confirmation_token' => $summary['token']],
        )->assertRedirect(route('ffb-receive.show', $candidate));

        $predecessor->refresh();
        $candidate->refresh();
        $this->assertSame('superseded', $predecessor->status);
        $this->assertSame('confirmed', $candidate->status);
        $this->assertSame($original['confirmed_by'], $predecessor->confirmed_by);
        $this->assertSame(Carbon::parse($original['confirmed_at'])->toDateTimeString(), $predecessor->confirmed_at->toDateTimeString());
        $this->assertSame(
            json_decode($original['confirmation_snapshot'], true, flags: JSON_THROW_ON_ERROR),
            $predecessor->confirmation_snapshot,
        );
        $this->assertSame($original['confirmed_daily_total_mt'], $predecessor->confirmed_daily_total_mt);
        $this->assertSame(1, FfbImportBatch::where('mill_id', $this->kbb->id)
            ->whereDate('receipt_date', '2026-11-10')->where('status', 'confirmed')->count());
        $this->assertSame(0, DailyOperation::where('mill_id', $this->kbb->id)
            ->whereDate('tarikh', '2026-11-10')->count());
        $this->assertSame(0, $candidate->tickets()->count());
        $this->assertTrue(AuditLog::where('action', 'ffb_correction_confirmed')
            ->where('model_id', $candidate->id)->exists());

        try {
            DB::table('audit_logs')->where('action', 'ffb_correction_confirmed')->update(['action' => 'edited']);
            $this->fail('FFB correction audit rows must be immutable.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_excel_replacement_runs_existing_validation_and_requires_fresh_acknowledgement(): void
    {
        $predecessor = $this->confirmedZero($this->kahang, $this->pegawaiKahang, '2026-11-12');
        $centre = WeighbridgeCentre::create([
            'code' => 'S3OWN', 'name' => 'Stage 3 Owned Source',
            'source_category' => 'ppnj_owned', 'is_active' => true,
        ]);
        FfbSourceGroup::create([
            'heading' => 'STAGE3 GROUP', 'source_category' => 'ppnj_owned',
            'weighbridge_centre_id' => $centre->id, 'is_active' => true,
        ]);
        $file = $this->workbook('2026-11-12', 'S3-TICKET-1', '8.250000');

        $this->actingAs($this->pegawaiKahang)->post(
            route('ffb-receive.correction.store', $predecessor),
            [
                'receipt_type' => 'excel',
                'correction_reason' => 'Tiket timbang latihan tertinggal',
                'workbook' => $file,
                'confirmed_weight_unit' => 'MT',
                'control_ticket_count' => 1,
                'control_total_mt' => '8.250000',
            ],
        )->assertRedirect();

        $candidate = FfbImportBatch::where('supersedes_batch_id', $predecessor->id)->firstOrFail();
        $this->assertSame('ready', $candidate->status);
        $this->assertSame(1, $candidate->tickets()->count());
        $summary = app(FfbBatchConfirmationService::class)->summaryCorrection($candidate);
        $this->assertSame([], $summary['errors']);
        $this->assertCount(1, $summary['comparison']['tickets']['added']);

        $this->actingAs($this->pegawaiKahang)->post(
            route('ffb-receive.correction.confirm', $candidate),
            ['acknowledgement' => '1', 'confirmation_token' => str_repeat('0', 64)],
        )->assertSessionHasErrors('correction');
        $this->assertSame('confirmed', $predecessor->fresh()->status);
        $this->assertSame('ready', $candidate->fresh()->status);

        $this->actingAs($this->pegawaiKahang)->post(
            route('ffb-receive.correction.confirm', $candidate),
            ['acknowledgement' => '1', 'confirmation_token' => $summary['token']],
        )->assertRedirect(route('ffb-receive.show', $candidate));
        $this->assertSame('superseded', $predecessor->fresh()->status);
        $this->assertSame('confirmed', $candidate->fresh()->status);
        $this->assertSame('8.25', $candidate->fresh()->confirmed_daily_total_mt);
    }

    public function test_role_and_mill_permissions_are_enforced_for_correction(): void
    {
        $batch = $this->confirmedZero($this->kbb, $this->pegawaiKbb, '2026-11-15');

        $this->actingAs($this->pegawaiKahang)
            ->get(route('ffb-receive.correction.create', $batch))->assertForbidden();
        $this->actingAs($this->marketing)
            ->get(route('ffb-receive.correction.create', $batch))->assertForbidden();
        $this->actingAs($this->pegawaiKbb)->post(
            route('ffb-receive.correction.store', $batch),
            ['receipt_type' => 'zero_receipt', 'correction_reason' => '   '],
        )->assertSessionHasErrors('correction_reason');

        $this->actingAs($this->admin)
            ->get(route('ffb-receive.correction.create', $batch))->assertOk();
    }

    public function test_december_unlinked_correction_activates_without_retroactively_linking_daily_operations(): void
    {
        $predecessor = $this->confirmedZero($this->kbb, $this->pegawaiKbb, '2026-12-03');
        $candidate = app(\App\Services\FfbBatchCorrectionService::class)->createZeroReplacement(
            $predecessor->id, $this->pegawaiKbb, 'Pembetulan rasmi sifar',
        );
        $summary = app(FfbBatchConfirmationService::class)->summaryCorrection($candidate);

        $confirmed = app(FfbBatchConfirmationService::class)->confirmCorrection(
            $candidate->id, $this->pegawaiKbb, $summary['token'],
        );

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame('superseded', $predecessor->fresh()->status);
        $this->assertNull($confirmed->daily_operation_id);
        $this->assertSame(0, DailyOperation::where('mill_id', $this->kbb->id)
            ->whereDate('tarikh', '2026-12-03')->count());
    }

    public function test_active_batch_cannot_have_multiple_pending_replacements(): void
    {
        $predecessor = $this->confirmedZero($this->kahang, $this->pegawaiKahang, '2026-12-05');
        $corrections = app(\App\Services\FfbBatchCorrectionService::class);
        $candidate = $corrections->createZeroReplacement(
            $predecessor->id, $this->pegawaiKahang, 'Pembetulan pertama',
        );

        try {
            $corrections->createZeroReplacement(
                $predecessor->id, $this->pegawaiKahang, 'Pembetulan pendua',
            );
            $this->fail('A pending replacement must prevent a second correction candidate.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('confirmed', $predecessor->fresh()->status);
            $this->assertSame('ready', $candidate->fresh()->status);
            $this->assertSame(1, FfbImportBatch::where('supersedes_batch_id', $predecessor->id)
                ->whereIn('status', ['uploaded', 'needs_review', 'ready'])->count());
        }
    }

    public function test_december_linked_correction_updates_only_bts_and_propagates_through_month_reset(): void
    {
        $priorBatch = $this->confirmedZero($this->kbb, $this->pegawaiKbb, '2026-11-30');
        $november = $this->operation($this->kbb, $this->pegawaiKbb, '2026-11-30', null, 0, 0, 5, 5);
        $predecessor = $this->confirmedExcel($this->kbb, $this->pegawaiKbb, '2026-12-01', '10.00');
        $dayOne = $this->operation($this->kbb, $this->pegawaiKbb, '2026-12-01', $predecessor, 10, 0, 0, 10);
        $predecessor->update(['daily_operation_id' => $dayOne->id, 'synced_at' => now()]);
        $nextBatch = $this->confirmedExcel($this->kbb, $this->pegawaiKbb, '2026-12-02', '4.00');
        $dayTwo = $this->operation($this->kbb, $this->pegawaiKbb, '2026-12-02', $nextBatch, 4, 2, 4, 6);
        $nextBatch->update(['daily_operation_id' => $dayTwo->id, 'synced_at' => now()]);
        $unrelatedBefore = $dayOne->fresh()->only([
            'bts_diproses', 'pengeluaran_cpo', 'pengeluaran_pk', 'stok_cpo', 'stok_pk',
            'jam_operasi', 'produksi_cpo', 'produksi_pk', 'oer', 'ker', 'throughput',
            'utilisation_rate', 'operation_status', 'catatan_tambahan',
        ]);

        $candidate = app(\App\Services\FfbBatchCorrectionService::class)->createZeroReplacement(
            $predecessor->id, $this->pegawaiKbb, 'Penerimaan rasmi disahkan sifar',
        );
        $summary = app(FfbBatchConfirmationService::class)->summaryCorrection($candidate);
        $this->assertSame([], $summary['errors']);
        $confirmed = app(FfbBatchConfirmationService::class)->confirmCorrection(
            $candidate->id, $this->pegawaiKbb, $summary['token'],
        );

        $dayOne->refresh();
        $dayTwo->refresh();
        $this->assertSame($confirmed->id, $dayOne->ffb_import_batch_id);
        $this->assertSame('0.00', $dayOne->bts_diterima);
        $this->assertSame('0.00', $dayOne->baki_bts_semalam);
        $this->assertSame('0.00', $dayOne->baki_bts_selepas_diproses);
        $this->assertSame('0.00', $dayTwo->baki_bts_semalam);
        $this->assertSame('2.00', $dayTwo->baki_bts_selepas_diproses);
        $this->assertSame($unrelatedBefore, $dayOne->only(array_keys($unrelatedBefore)));
        $this->assertSame($dayOne->id, $confirmed->daily_operation_id);
        $this->assertSame(1, AuditLog::where('action', 'ffb_correction_daily_reconciled')
            ->where('model_id', $dayOne->id)->count());
    }

    public function test_negative_balance_rolls_back_batch_transitions_and_official_reconciliation(): void
    {
        $predecessor = $this->confirmedExcel($this->kbb, $this->pegawaiKbb, '2026-12-04', '10.00');
        $operation = $this->operation($this->kbb, $this->pegawaiKbb, '2026-12-04', $predecessor, 10, 11, 0, -1);
        $predecessor->update(['daily_operation_id' => $operation->id, 'synced_at' => now()]);
        $candidate = app(\App\Services\FfbBatchCorrectionService::class)->createZeroReplacement(
            $predecessor->id, $this->pegawaiKbb, 'Pembetulan sifar yang ditolak',
        );
        $summary = app(FfbBatchConfirmationService::class)->summaryCorrection($candidate);

        try {
            app(FfbBatchConfirmationService::class)->confirmCorrection(
                $candidate->id, $this->pegawaiKbb, $summary['token'],
            );
            $this->fail('A negative BTS balance must reject and roll back the correction.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('confirmed', $predecessor->fresh()->status);
            $this->assertSame('ready', $candidate->fresh()->status);
            $this->assertSame($predecessor->id, $operation->fresh()->ffb_import_batch_id);
            $this->assertSame('10.00', $operation->fresh()->bts_diterima);
        }
    }

    public function test_mysql_unique_slot_and_immutable_batch_trigger_reject_direct_sql_bypasses(): void
    {
        $batch = $this->confirmedZero($this->kbb, $this->pegawaiKbb, '2026-11-20');

        try {
            DB::table('ffb_import_batches')->where('id', $batch->id)->update(['confirmed_daily_total_mt' => '9.00']);
            $this->fail('Confirmed batch contents must be locked.');
        } catch (QueryException) {
            $this->assertSame('confirmed', $batch->fresh()->status);
        }

        try {
            DB::table('ffb_import_batches')->insert($this->zeroBatchAttributes(
                $this->kbb, $this->pegawaiKbb, '2026-11-20', '1.00',
            ));
            $this->fail('The generated active slot must prevent two confirmed versions.');
        } catch (QueryException) {
            $this->assertSame(1, FfbImportBatch::where('mill_id', $this->kbb->id)
                ->whereDate('receipt_date', '2026-11-20')->where('status', 'confirmed')->count());
        }
    }

    public function test_two_independent_mysql_transactions_serialize_same_batch_corrections(): void
    {
        if (app()->environment('master-uat')) {
            $this->markTestSkipped('Concurrency worker is restricted to Stage 3; it commits protected fixture history.');
        }
        $mill = Mill::create([
            'code' => 'S3'.Str::upper(Str::random(8)),
            'name' => 'Stage3 Concurrent Mill',
            'location' => 'Johor',
            'is_active' => true,
        ]);
        $firstOfficer = $this->user(Role::PEGAWAI_KILANG, $mill, 'concurrent-a-'.Str::lower(Str::random(8)));
        $secondOfficer = $this->user(Role::PEGAWAI_KILANG, $mill, 'concurrent-b-'.Str::lower(Str::random(8)));
        $predecessor = $this->confirmedExcel($mill, $firstOfficer, '2026-12-07', '10.00');
        $operation = $this->operation($mill, $firstOfficer, '2026-12-07', $predecessor, 10, 0, 0, 10);
        $predecessor->update(['daily_operation_id' => $operation->id, 'synced_at' => now()]);

        $corrections = app(\App\Services\FfbBatchCorrectionService::class);
        $winnerCandidate = $corrections->createZeroReplacement(
            $predecessor->id, $firstOfficer, 'Concurrent correction A',
        );
        $loserCandidate = FfbImportBatch::create([
            'mill_id' => $mill->id,
            'receipt_date' => $predecessor->receipt_date,
            'uploaded_by' => $secondOfficer->id,
            'receipt_type' => 'zero_receipt',
            'status' => 'ready',
            'ticket_count' => 0,
            'parsed_total_mt' => '0.000000',
            'has_complete_weight_total' => true,
            'unresolved_count' => 0,
            'validation_summary' => ['errors' => [], 'warnings' => [], 'declaration' => 'concurrent synthetic correction'],
            'validated_at' => now(),
            'preview_hash' => hash('sha256', Str::random(32)),
            'confirmation_reason' => 'Concurrent correction B',
            'supersedes_batch_id' => $predecessor->id,
        ]);

        $confirmation = app(FfbBatchConfirmationService::class);
        $winnerToken = $confirmation->summaryCorrection($winnerCandidate)['token'];
        $loserToken = $confirmation->summaryCorrection($loserCandidate)['token'];
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stage3-correction-'.Str::uuid();
        $this->assertTrue(mkdir($directory));

        $winnerProcess = null;
        $loserProcess = null;
        try {
            DB::commit();
            $winnerProcess = $this->startConcurrencyWorker(
                'winner', $winnerCandidate, $firstOfficer, $winnerToken, $directory,
            );
            $this->waitForConcurrencyFile($directory, 'winner-locked.json', $winnerProcess);
            $loserProcess = $this->startConcurrencyWorker(
                'loser', $loserCandidate, $secondOfficer, $loserToken, $directory,
            );
            $this->waitForConcurrencyFile($directory, 'loser-attempting.json', $loserProcess);
            usleep(750000);
            $this->assertConcurrentLoserBlocked($directory);

            file_put_contents($directory.DIRECTORY_SEPARATOR.'run-winner', 'run', LOCK_EX);
            $this->waitForConcurrencyFile($directory, 'winner-prepared.json', $winnerProcess);
            usleep(750000);
            $this->assertConcurrentLoserBlocked($directory);

            file_put_contents($directory.DIRECTORY_SEPARATOR.'commit-winner', 'commit', LOCK_EX);
            $winnerOutput = $this->finishConcurrencyWorker($winnerProcess);
            $winnerProcess = null;
            $loserOutput = $this->finishConcurrencyWorker($loserProcess);
            $loserProcess = null;

            $this->assertSame(0, $winnerOutput['exit_code'], $winnerOutput['stderr']);
            $this->assertSame(0, $loserOutput['exit_code'], $loserOutput['stderr']);
            $winnerResult = json_decode(
                file_get_contents($directory.DIRECTORY_SEPARATOR.'winner-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $loserResult = json_decode(
                file_get_contents($directory.DIRECTORY_SEPARATOR.'loser-result.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            $this->assertSame('confirmed', $winnerResult['status']);
            $this->assertSame('rejected', $loserResult['status']);
            $this->assertNotSame($winnerResult['connection_id'], $loserResult['connection_id']);
            $this->assertSame('superseded', $predecessor->fresh()->status);
            $this->assertSame('confirmed', $winnerCandidate->fresh()->status);
            $this->assertSame('ready', $loserCandidate->fresh()->status);
            $this->assertSame(1, FfbImportBatch::where('mill_id', $mill->id)
                ->whereDate('receipt_date', '2026-12-07')->where('status', 'confirmed')->count());
            $this->assertSame($winnerCandidate->id, $operation->fresh()->ffb_import_batch_id);
            $this->assertSame('0.00', $operation->fresh()->bts_diterima);
            $this->assertSame('0.00', $operation->fresh()->baki_bts_selepas_diproses);
            $this->assertSame(1, AuditLog::where('action', 'ffb_correction_confirmed')
                ->where('model_type', 'FfbImportBatch')
                ->whereIn('model_id', [$winnerCandidate->id, $loserCandidate->id])->count());
            $this->assertSame(1, AuditLog::where('action', 'ffb_correction_daily_reconciled')
                ->where('model_id', $operation->id)->count());
        } finally {
            if (is_dir($directory)) {
                if ($winnerProcess !== null) {
                    file_put_contents($directory.DIRECTORY_SEPARATOR.'run-winner', 'run', LOCK_EX);
                    file_put_contents($directory.DIRECTORY_SEPARATOR.'commit-winner', 'commit', LOCK_EX);
                    $this->finishConcurrencyWorker($winnerProcess);
                }
                if ($loserProcess !== null) {
                    $this->finishConcurrencyWorker($loserProcess);
                }
                foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                rmdir($directory);
            }
        }
    }

    private function assertConcurrentLoserBlocked(string $directory): void
    {
        $this->assertFalse(
            is_file($directory.DIRECTORY_SEPARATOR.'loser-locked.json')
                || is_file($directory.DIRECTORY_SEPARATOR.'loser-result.json'),
            'Loser passed the mill row lock while winner transaction remained open. '
                .'loser-locked='.(@file_get_contents($directory.DIRECTORY_SEPARATOR.'loser-locked.json') ?: 'missing')
                .' loser-result='.(@file_get_contents($directory.DIRECTORY_SEPARATOR.'loser-result.json') ?: 'missing')
                .' winner='.(@file_get_contents($directory.DIRECTORY_SEPARATOR.'winner-locked.json') ?: 'missing')
                .' prepared='.(@file_get_contents($directory.DIRECTORY_SEPARATOR.'winner-prepared.json') ?: 'missing'),
        );
    }

    private function startConcurrencyWorker(
        string $mode,
        FfbImportBatch $batch,
        User $actor,
        string $token,
        string $directory,
    ): array {
        $command = [
            PHP_BINARY,
            base_path('tests/Support/Stage3CorrectionConcurrencyWorker.php'),
            $mode,
            (string) $batch->id,
            (string) $actor->id,
            $token,
            $directory,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());
        if (! is_resource($process)) {
            throw new \RuntimeException('Unable to start an independent MySQL correction worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes, 'mode' => $mode];
    }

    private function waitForConcurrencyFile(string $directory, string $file, array $worker): void
    {
        $path = $directory.DIRECTORY_SEPARATOR.$file;
        $deadline = microtime(true) + 30;
        while (! is_file($path)) {
            $status = proc_get_status($worker['process']);
            if (! $status['running'] || microtime(true) >= $deadline) {
                $stderr = stream_get_contents($worker['pipes'][2]);
                throw new \RuntimeException(
                    "Concurrency worker failed before {$file}; exit {$status['exitcode']}: {$stderr}"
                );
            }
            usleep(10000);
        }
    }

    private function finishConcurrencyWorker(array $worker): array
    {
        $deadline = microtime(true) + 30;
        do {
            $status = proc_get_status($worker['process']);
            if (! $status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($worker['process']);
            throw new \RuntimeException("Concurrency worker {$worker['mode']} did not terminate.");
        }

        $stdout = stream_get_contents($worker['pipes'][1]);
        $stderr = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        return ['exit_code' => $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function user(string $roleName, ?Mill $mill, string $suffix): User
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['label' => $roleName]);

        return User::create([
            'name' => 'Stage3 '.$suffix,
            'email' => $suffix.'-'.Str::lower(Str::random(8)).'@stage3.test',
            'password' => 'stage3-local-test',
            'role_id' => $role->id,
            'mill_id' => $mill?->id,
            'is_active' => true,
        ]);
    }

    private function confirmedZero(
        Mill $mill,
        User $actor,
        string $date,
    ): FfbImportBatch {
        return FfbImportBatch::create($this->zeroBatchAttributes($mill, $actor, $date, '0.00'));
    }

    private function confirmedExcel(Mill $mill, User $actor, string $date, string $weight): FfbImportBatch
    {
        $suffix = strtoupper(Str::random(8));
        $centre = WeighbridgeCentre::create([
            'code' => 'S'.$suffix, 'name' => 'Synthetic '.$suffix,
            'source_category' => 'ppnj_owned', 'is_active' => true,
        ]);
        $group = FfbSourceGroup::create([
            'heading' => 'GROUP '.$suffix, 'source_category' => 'ppnj_owned',
            'weighbridge_centre_id' => $centre->id, 'is_active' => true,
        ]);
        $time = now();
        $batch = FfbImportBatch::create([
            'mill_id' => $mill->id,
            'receipt_date' => $date,
            'uploaded_by' => $actor->id,
            'receipt_type' => 'excel',
            'original_filename' => 'synthetic-'.$suffix.'.xlsx',
            'storage_disk' => 'ffb_receive',
            'storage_path' => 'synthetic/'.$suffix.'.xlsx',
            'file_sha256' => hash('sha256', $suffix),
            'file_size_bytes' => 1,
            'detected_format' => 'Xlsx',
            'status' => 'ready',
            'confirmed_weight_unit' => 'MT',
            'ticket_count' => 1,
            'parsed_total_mt' => $weight,
            'has_complete_weight_total' => true,
            'unresolved_count' => 0,
            'validation_summary' => ['errors' => [], 'warnings' => []],
            'control_ticket_count' => 1,
            'control_total_mt' => $weight,
            'reconciliation_evidence' => ['ticket_count_difference' => 0, 'total_mt_difference' => '0.000000'],
            'validated_at' => $time,
            'preview_hash' => hash('sha256', 'preview-'.$suffix),
        ]);
        $ticketNumber = 'TICKET-'.$suffix;
        $batch->tickets()->create([
            'worksheet' => 'Sheet1',
            'row_number' => 4,
            'heading_row_number' => 3,
            'original_heading' => 'GROUP '.$suffix,
            'original_source_code' => $suffix,
            'ticket_number' => $ticketNumber,
            'normalized_ticket_number' => $ticketNumber,
            'ticket_date' => $date,
            'do_number' => 'DO-'.$suffix,
            'vehicle_number' => 'ABC 123',
            'raw_values' => ['weight' => $weight],
            'weight_unit' => 'MT',
            'weight_unit_origin' => 'workbook',
            'weight_mt' => $weight,
            'ffb_source_group_id' => $group->id,
            'weighbridge_centre_id' => $centre->id,
            'source_category' => 'ppnj_owned',
            'resolution_snapshot' => [
                'requires_review' => false,
                'source_category' => 'ppnj_owned',
                'source' => ['id' => $centre->id],
                'group' => ['id' => $group->id],
            ],
            'requires_review' => false,
            'review_reasons' => [],
        ]);
        $batch->update([
            'status' => 'confirmed',
            'confirmed_by' => $actor->id,
            'confirmed_at' => $time,
            'confirmed_daily_total_mt' => number_format((float) $weight, 2, '.', ''),
            'confirmation_snapshot' => ['synthetic_test' => true, 'total' => $weight],
        ]);

        return $batch->fresh();
    }

    private function zeroBatchAttributes(Mill $mill, User $actor, string $date, string $total): array
    {
        return [
            'mill_id' => $mill->id,
            'receipt_date' => $date,
            'uploaded_by' => $actor->id,
            'receipt_type' => 'zero_receipt',
            'status' => 'confirmed',
            'ticket_count' => 0,
            'parsed_total_mt' => '0.000000',
            'has_complete_weight_total' => true,
            'unresolved_count' => 0,
            'validation_summary' => ['errors' => [], 'warnings' => [], 'declaration' => 'synthetic zero test'],
            'validated_at' => now(),
            'preview_hash' => hash('sha256', $mill->id.'|'.$date.'|'.$total),
            'confirmed_by' => $actor->id,
            'confirmed_at' => now(),
            'confirmation_snapshot' => ['synthetic_test' => true, 'total' => $total],
            'confirmed_daily_total_mt' => $total,
        ];
    }

    private function operation(
        Mill $mill,
        User $officer,
        string $date,
        ?FfbImportBatch $batch,
        float $received,
        float $processed,
        float $opening,
        float $closing,
    ): DailyOperation {
        $operation = new DailyOperation([
            'tarikh' => $date,
            'mill_id' => $mill->id,
            'shift' => 'Harian',
            'officer_id' => $officer->id,
            'operation_status' => 'Operasi',
            'bts_diterima' => $received,
            'bts_diproses' => $processed,
            'baki_bts_semalam' => $opening,
            'baki_bts_selepas_diproses' => $closing,
            'jam_operasi' => 8,
            'pengeluaran_cpo' => 2,
            'pengeluaran_pk' => 1,
            'stok_cpo' => 30,
            'stok_pk' => 20,
            'produksi_cpo' => 5,
            'produksi_pk' => 3,
            'oer' => 50,
            'ker' => 30,
            'throughput' => 10,
            'utilisation_rate' => 20,
            'catatan_tambahan' => 'Synthetic unrelated values',
            'status' => 'submitted',
        ]);
        if ($batch) {
            $operation->ffbImportBatch()->associate($batch);
        }
        $operation->save();

        return $operation;
    }

    private function workbook(string $date, string $ticketNumber, string $weight): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('K1', 'Nett Weight MT');
        $sheet->setCellValue('A3', 'Name :');
        $sheet->setCellValue('C3', 'STAGE3 GROUP');
        foreach ([
            'A' => '1', 'B' => 'STAGE3', 'E' => $date, 'F' => $ticketNumber,
            'H' => 'DO-1', 'I' => 'ABC 123', 'K' => $weight,
        ] as $column => $value) {
            $sheet->setCellValueExplicit(
                $column.'4',
                $value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'ffb-stage3-');
        $this->tempFiles[] = $path;
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'stage3-correction.xlsx', null, null, true);
    }
}
