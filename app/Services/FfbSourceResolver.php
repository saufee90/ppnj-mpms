<?php

namespace App\Services;

use App\Models\FfbSourceAlias;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\WeighbridgeCentre;

class FfbSourceResolver
{
    private const MILL_HEADING_SCOPE = [
        'BBJ' => [
            'BANGAS AGRO ENTERPRISE',
            'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD',
            'LADANG BUKIT BUJANG',
            'LADANG KONSORTIUM PPNJ/PPK',
            'LADANG USAHASAMA PKAYIS&KDSSB',
            'MEWAH MEGA OIL PALM SDN BHD',
            'PERTUBUHAN PELADANG NEGERI JOHOR',
        ],
        'KHG' => [
            'BIS JAYA TRADING SDN BHD',
            'BIS JAYA TRADING SDN BHD (A)',
            'GUAN LENG TRADING SDN BHD',
            'HJ MANSOR FAMILY AND SONS SDN BHD',
            'KAHANG OIL PALM TRADING',
            'LADANG KAHANG 1',
            'LADANG KAHANG 2',
            'LADANG USAHASAMA PPNJ / JELAPANG MEGAH',
            'LADANG USAHASAMA PPNJ / PPK KLUANG UTARA',
            'LADANG WAKAF JEMALUANG',
            'PERTUBUHAN PELADANG NEGERI JOHOR',
        ],
    ];

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

        if (($reason = $source->ffbGroupingReviewReason()) !== null) {
            return $this->review($heading, $code, $reason, $group);
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

    public function resolveForMill(string $millCode, string $heading, string $code): FfbSourceResolution
    {
        $allowedHeadings = self::MILL_HEADING_SCOPE[$millCode] ?? null;
        if ($allowedHeadings !== null && trim($heading) !== '') {
            $normalizedHeading = FfbSourceAlias::normalizeCode($heading);
            $allowed = in_array($normalizedHeading, array_map(
                fn (string $candidate): string => FfbSourceAlias::normalizeCode($candidate),
                $allowedHeadings,
            ), true);
            $otherMillOwnsHeading = false;
            foreach (self::MILL_HEADING_SCOPE as $otherMill => $headings) {
                if ($otherMill === $millCode) {
                    continue;
                }
                foreach ($headings as $candidate) {
                    if (FfbSourceAlias::normalizeCode($candidate) === $normalizedHeading) {
                        $otherMillOwnsHeading = true;
                        break 2;
                    }
                }
            }

            if ($otherMillOwnsHeading && ! $allowed) {
                return $this->review($heading, $code, 'heading_not_assigned_to_mill');
            }
        }

        return $this->resolve($heading, $code);
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
