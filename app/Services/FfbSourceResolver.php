<?php

namespace App\Services;

use App\Models\FfbSourceAlias;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\WeighbridgeCentre;

class FfbSourceResolver
{
    public function __construct(
        private FfbSourceGroup $groups,
        private FfbSourceCodeMapping $mappings,
    ) {
    }

    /**
     * Resolve Name: heading values and Code cells without modifying the originals.
     * Results describe current masters, not immutable historical ticket snapshots.
     */
    public function resolve(string $heading, string $code): FfbSourceResolution
    {
        if (trim($heading) === '' || trim($code) === '') {
            return $this->review($heading, $code, 'blank_heading_or_code');
        }

        $groups = $this->groups->newQuery()
            ->where('normalized_heading', FfbSourceAlias::normalizeCode($heading))
            ->with('weighbridgeCentre.ppk')->limit(2)->get();

        if ($groups->count() !== 1) {
            return $this->review($heading, $code, $groups->isEmpty() ? 'unknown_heading' : 'ambiguous_heading');
        }

        $group = $groups->first();

        if (! $group->is_active) {
            return $this->review($heading, $code, 'inactive_heading', $group);
        }

        $mappings = $this->mappings->newQuery()
            ->where('ffb_source_group_id', $group->id)
            ->where('normalized_code', FfbSourceAlias::normalizeCode($code))
            ->with('weighbridgeCentre.ppk')->limit(2)->get();

        if ($mappings->count() > 1) {
            return $this->review($heading, $code, 'ambiguous_code', $group);
        }

        $mapping = $mappings->first();

        if ($mapping && ! $mapping->is_active) {
            return $this->review($heading, $code, 'inactive_code_mapping', $group);
        }

        if ($group->weighbridge_centre_id !== null) {
            // Direct estate/supplier headings own their block codes, not global aliases.
            if ($mapping && $mapping->weighbridge_centre_id !== $group->weighbridge_centre_id) {
                return $this->review($heading, $code, 'conflicting_heading_and_code', $group);
            }

            $source = $group->weighbridgeCentre;
        } else {
            if (! $mapping) {
                return $this->review($heading, $code, 'unknown_contextual_code', $group);
            }

            $source = $mapping->weighbridgeCentre;
        }

        if (! $source || ! $source->is_active) {
            return $this->review($heading, $code, 'missing_or_inactive_source', $group);
        }

        if (! in_array($group->source_category, WeighbridgeCentre::SOURCE_CATEGORIES, true)
            || $source->source_category !== $group->source_category) {
            return $this->review($heading, $code, 'source_category_mismatch', $group);
        }

        if ($source->ppk_id !== null && (! $source->ppk || ! $source->ppk->is_active)) {
            return $this->review($heading, $code, 'missing_or_inactive_ppk', $group);
        }

        return new FfbSourceResolution(
            originalHeading: $heading,
            originalCode: $code,
            requiresReview: false,
            reviewReason: null,
            group: $group,
            source: $source,
            ppk: $source->ppk,
            sourceCategory: $source->source_category,
        );
    }

    private function review(
        string $heading,
        string $code,
        string $reason,
        ?FfbSourceGroup $group = null,
    ): FfbSourceResolution {
        return new FfbSourceResolution($heading, $code, true, $reason, $group);
    }
}
