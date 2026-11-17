<?php

namespace Tests\Unit\Models;

use App\Models\DailyOperation;
use App\Models\FfbImportBatch;
use App\Models\FfbSourceGroup;
use App\Models\FfbWeighbridgeTicket;
use App\Models\Mill;
use App\Models\Ppk;
use App\Models\User;
use App\Models\WeighbridgeCentre;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\MySqlConnection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FfbReceivePersistenceTest extends TestCase
{
    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $connection = new MySqlConnection(function () {
            throw new LogicException('Database access is forbidden in this unit test.');
        });
        $resolver = new ConnectionResolver(['unit' => $connection]);
        $resolver->setDefaultConnection('unit');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver === null) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->previousResolver);
        }
        parent::tearDown();
    }

    public function test_batch_casts_preserve_precision_and_confirmation_evidence(): void
    {
        $snapshot = ['ticket_count' => 81, 'total_mt' => '1116.080000'];
        $batch = new FfbImportBatch([
            'mill_id' => '1', 'receipt_date' => '2026-10-28',
            'uploaded_by' => '2', 'file_size_bytes' => '1024',
            'ticket_count' => '81', 'parsed_total_mt' => '1116.080001',
            'control_total_mt' => '1116.080000', 'confirmed_daily_total_mt' => '1116.08',
            'validation_summary' => ['issues' => []],
            'reconciliation_evidence' => ['count_difference' => 0],
            'confirmation_snapshot' => $snapshot,
            'validated_at' => '2026-10-29 10:00:00',
            'confirmed_at' => '2026-10-29 10:01:00',
            'synced_at' => '2026-10-29 10:01:00',
        ]);

        $this->assertSame('ffb_import_batches', $batch->getTable());
        $this->assertSame('uploaded', $batch->status);
        $this->assertFalse($batch->has_complete_weight_total);
        $this->assertSame(1, $batch->mill_id);
        $this->assertSame(1024, $batch->file_size_bytes);
        $this->assertSame(81, $batch->ticket_count);
        $this->assertSame('2026-10-28', $batch->receipt_date->toDateString());
        $this->assertSame('1116.080001', $batch->parsed_total_mt);
        $this->assertSame('1116.080000', $batch->control_total_mt);
        $this->assertSame('1116.08', $batch->confirmed_daily_total_mt);
        $this->assertSame($snapshot, $batch->confirmation_snapshot);
        $this->assertSame(['issues' => []], $batch->validation_summary);
        $this->assertSame(['count_difference' => 0], $batch->reconciliation_evidence);
        $this->assertSame('2026-10-29 10:01:00', $batch->confirmed_at->toDateTimeString());
        $this->assertSame('Harian', FfbImportBatch::SYNC_SHIFT);
        $this->assertNotContains('active_slot', $batch->getFillable());
        $this->assertNotContains('ffb_import_batch_id', (new DailyOperation())->getFillable());
    }

    public function test_ticket_preserves_originals_leading_zeroes_and_nullable_invalid_values(): void
    {
        $raw = ['B' => ' code ', 'F' => '000123', 'K' => '=SUM(K1:K2)'];
        $snapshot = ['centre' => ['id' => 3, 'code' => 'OLD', 'name' => 'Original source']];
        $ticket = new FfbWeighbridgeTicket([
            'original_heading' => ' Heading ', 'original_source_code' => ' code ',
            'ticket_number' => '000123', 'do_number' => '000456', 'vehicle_number' => 'ABC 123',
            'ticket_date' => '2026-10-28', 'raw_values' => $raw,
            'resolution_snapshot' => $snapshot, 'weight_mt' => '0.001234',
            'row_number' => '12', 'review_reasons' => ['duplicate_ticket_number'],
        ]);

        $this->assertSame('ffb_weighbridge_tickets', $ticket->getTable());
        $this->assertTrue($ticket->requires_review);
        $this->assertSame(' Heading ', $ticket->original_heading);
        $this->assertSame(' code ', $ticket->original_source_code);
        $this->assertSame('000123', $ticket->ticket_number);
        $this->assertSame('000456', $ticket->do_number);
        $this->assertSame($raw, $ticket->raw_values);
        $this->assertSame($snapshot, $ticket->resolution_snapshot);
        $this->assertSame('0.001234', $ticket->weight_mt);
        $this->assertSame(12, $ticket->row_number);
        $this->assertSame(['duplicate_ticket_number'], $ticket->review_reasons);
        $this->assertNull((new FfbWeighbridgeTicket())->weight_mt);
        $this->assertNull((new FfbWeighbridgeTicket())->ticket_date);
        $this->assertNull((new FfbImportBatch())->parsed_total_mt);

        $ticket->setRelation('weighbridgeCentre', new WeighbridgeCentre(['code' => 'NEW', 'name' => 'Renamed']));
        $this->assertSame($snapshot, $ticket->resolution_snapshot);
    }

    public static function relationships(): array
    {
        return [
            [FfbImportBatch::class, 'mill', Mill::class, 'mill_id', BelongsTo::class],
            [FfbImportBatch::class, 'uploader', User::class, 'uploaded_by', BelongsTo::class],
            [FfbImportBatch::class, 'confirmer', User::class, 'confirmed_by', BelongsTo::class],
            [FfbImportBatch::class, 'dailyOperation', DailyOperation::class, 'daily_operation_id', BelongsTo::class],
            [FfbImportBatch::class, 'supersedesBatch', FfbImportBatch::class, 'supersedes_batch_id', BelongsTo::class],
            [FfbImportBatch::class, 'revisions', FfbImportBatch::class, 'supersedes_batch_id', HasMany::class],
            [FfbImportBatch::class, 'tickets', FfbWeighbridgeTicket::class, 'ffb_import_batch_id', HasMany::class],
            [FfbWeighbridgeTicket::class, 'importBatch', FfbImportBatch::class, 'ffb_import_batch_id', BelongsTo::class],
            [FfbWeighbridgeTicket::class, 'sourceGroup', FfbSourceGroup::class, 'ffb_source_group_id', BelongsTo::class],
            [FfbWeighbridgeTicket::class, 'weighbridgeCentre', WeighbridgeCentre::class, 'weighbridge_centre_id', BelongsTo::class],
            [FfbWeighbridgeTicket::class, 'ppk', Ppk::class, 'ppk_id', BelongsTo::class],
            [DailyOperation::class, 'ffbImportBatch', FfbImportBatch::class, 'ffb_import_batch_id', BelongsTo::class],
            [DailyOperation::class, 'ffbImportBatches', FfbImportBatch::class, 'daily_operation_id', HasMany::class],
            [Mill::class, 'ffbImportBatches', FfbImportBatch::class, 'mill_id', HasMany::class],
            [User::class, 'uploadedFfbImportBatches', FfbImportBatch::class, 'uploaded_by', HasMany::class],
            [User::class, 'confirmedFfbImportBatches', FfbImportBatch::class, 'confirmed_by', HasMany::class],
            [Ppk::class, 'ffbWeighbridgeTickets', FfbWeighbridgeTicket::class, 'ppk_id', HasMany::class],
            [WeighbridgeCentre::class, 'ffbWeighbridgeTickets', FfbWeighbridgeTicket::class, 'weighbridge_centre_id', HasMany::class],
            [FfbSourceGroup::class, 'ffbWeighbridgeTickets', FfbWeighbridgeTicket::class, 'ffb_source_group_id', HasMany::class],
        ];
    }

    #[DataProvider('relationships')]
    public function test_relationship_keys_without_querying(
        string $modelClass,
        string $method,
        string $relatedClass,
        string $foreignKey,
        string $relationshipClass,
    ): void {
        $relation = (new $modelClass())->{$method}();

        $this->assertInstanceOf($relationshipClass, $relation);
        $this->assertInstanceOf($relatedClass, $relation->getRelated());
        $this->assertSame($foreignKey, $relation->getForeignKeyName());
    }
}
