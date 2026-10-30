<?php

namespace App\Services;

use App\Models\FfbSourceGroup;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;

final readonly class FfbSourceResolution
{
    public function __construct(
        public string $originalHeading,
        public string $originalCode,
        public bool $requiresReview,
        public ?string $reviewReason,
        public ?FfbSourceGroup $group = null,
        public ?WeighbridgeCentre $source = null,
        public ?Ppk $ppk = null,
        public ?string $sourceCategory = null,
    ) {
    }
}
