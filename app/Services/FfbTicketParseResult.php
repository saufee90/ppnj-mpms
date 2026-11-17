<?php

namespace App\Services;

/**
 * Counts include review/duplicate rows; totals include every successfully converted
 * weight, without deduplication. Null weights are excluded, not treated as zero.
 */
final readonly class FfbTicketParseResult
{
    /**
     * @param array<int, array<string, mixed>> $tickets
     * @param array<int, array<string, mixed>> $sourceGroups
     * @param array<int, array<string, mixed>> $issues
     */
    public function __construct(
        public array $tickets,
        public int $ticketCount,
        public float $totalMt,
        public array $sourceGroups,
        public array $issues,
        public bool $requiresReview,
        public bool $hasCompleteWeightTotal,
    ) {
    }
}
