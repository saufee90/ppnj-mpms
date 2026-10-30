<?php

namespace Database\Seeders;

use App\Models\FfbSourceAlias;
use App\Models\FfbSourceCodeMapping;
use App\Models\FfbSourceGroup;
use App\Models\Ppk;
use App\Models\WeighbridgeCentre;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Requires the approved PPK master; conflicts abort rather than overwrite existing mappings.
 */
class FfbSourceMappingSeeder extends Seeder
{
    public function run(
        Ppk $ppks,
        WeighbridgeCentre $centres,
        FfbSourceGroup $groups,
        FfbSourceCodeMapping $mappings,
    ): void {
        DB::transaction(function () use ($ppks, $centres, $groups, $mappings) {
            $ppkCodes = [
                'PPK-PARIT-RAJA', 'PPK-SIMPANG-LIMA', 'PPK-RENGIT', 'PPK-SRI-MEDAN',
                'PPK-BATU-ANAM', 'PPK-MUAR-SELATAN', 'PPK-PONTIAN-SELATAN', 'PPK-LEDANG-TANGKAK',
            ];
            $parents = [];

            foreach ($ppkCodes as $code) {
                $parent = $ppks->newQuery()->where('code', $code)->first();

                if (! $parent || ! $parent->is_active) {
                    throw new DomainException("Missing or inactive PPK master: {$code}. Seed the approved PPK master first.");
                }

                $parents[$code] = $parent->id;
            }

            $integratedGroup = $this->ensureGroup($groups, [
                'heading' => 'PERTUBUHAN PELADANG NEGERI JOHOR',
                'source_category' => 'ppnj_ppk_integrated',
                'weighbridge_centre_id' => null,
            ]);

            // Each distinct confirmed code remains a separate canonical source.
            $ppkSources = [
                ['PT RAJA 1', 'SRC-PT-RAJA-1', 'PPK-PARIT-RAJA'],
                ['PT RAJA 2', 'SRC-PT-RAJA-2', 'PPK-PARIT-RAJA'],
                ['PT RAJA 3', 'SRC-PT-RAJA-3', 'PPK-PARIT-RAJA'],
                ['B/UTARA 2', 'SRC-B-UTARA-2', 'PPK-SIMPANG-LIMA'],
                ['B.UTARA 3', 'SRC-B-UTARA-3', 'PPK-SIMPANG-LIMA'],
                ['RENGIT/1', 'SRC-RENGIT-1', 'PPK-RENGIT'],
                ['RENGIT/2', 'SRC-RENGIT-2', 'PPK-RENGIT'],
                ['S/MEDAN', 'SRC-S-MEDAN', 'PPK-SRI-MEDAN'],
                ['B/ANAM', 'SRC-B-ANAM', 'PPK-BATU-ANAM'],
                ['B/ANAM 1', 'SRC-B-ANAM-1', 'PPK-BATU-ANAM'],
                ['MUAR SEL 1', 'SRC-MUAR-SEL-1', 'PPK-MUAR-SELATAN'],
                ['PON SELATAN', 'SRC-PON-SELATAN', 'PPK-PONTIAN-SELATAN'],
                ['LEDANG 2', 'SRC-LEDANG-2', 'PPK-LEDANG-TANGKAK'],
            ];

            foreach ($ppkSources as [$sourceCode, $internalCode, $ppkCode]) {
                $centre = $this->ensureCentre($centres, $internalCode, [
                    'name' => $sourceCode,
                    'ppk_id' => $parents[$ppkCode],
                    'source_category' => 'ppnj_ppk_integrated',
                ]);
                $this->ensureMapping($mappings, $integratedGroup->id, $sourceCode, $centre->id);
            }

            $directSources = [
                ['SRC-LADANG-BUKIT-BUJANG', 'LADANG BUKIT BUJANG', 'Ladang Bukit Bujang', 'ppnj_owned'],
                ['SRC-LADANG-KAHANG-2', 'LADANG KAHANG 2', 'Ladang Kahang 2', 'ppnj_owned'],
                ['SRC-LADANG-KONSORTIUM-PPNJ-PPK', 'LADANG KONSORTIUM PPNJ/PPK', 'Ladang Konsortium PPNJ/PPK', 'ppnj_owned'],
                ['SRC-USAHASAMA-JELAPANG-MEGAH', 'LADANG USAHASAMA PPNJ / JELAPANG MEGAH', 'LADANG USAHASAMA PPNJ / JELAPANG MEGAH', 'ppnj_ppk_integrated'],
                ['SRC-USAHASAMA-PPK-KLUANG-UTARA', 'LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', 'LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', 'ppnj_ppk_integrated'],
                ['SRC-BANGAS-AGRO', 'BANGAS AGRO ENTERPRISE', 'BANGAS AGRO ENTERPRISE', 'external_supplier'],
                ['SRC-KOPERASI-SAWIT-MAMPAN-SEGAMAT', 'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD', 'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD', 'external_supplier'],
                ['SRC-MEWAH-MEGA', 'MEWAH MEGA OIL PALM SDN BHD', 'MEWAH MEGA OIL PALM SDN BHD', 'external_supplier'],
                ['SRC-BIS-JAYA', 'BIS JAYA TRADING SDN BHD', 'BIS JAYA TRADING SDN BHD', 'external_supplier'],
                ['SRC-GUAN-LENG', 'GUAN LENG TRADING SDN BHD', 'GUAN LENG TRADING SDN BHD', 'external_supplier'],
                ['SRC-HJ-MANSOR', 'HJ MANSOR FAMILY AND SONS SDN BHD', 'HJ MANSOR FAMILY AND SONS SDN BHD', 'external_supplier'],
                ['SRC-KAHANG-OIL-PALM', 'KAHANG OIL PALM TRADING', 'KAHANG OIL PALM TRADING', 'external_supplier'],
                ['SRC-USAHASAMA-PKAYIS-KDSSB', 'LADANG USAHASAMA PKAYIS&KDSSB', 'LADANG USAHASAMA PKAYIS&KDSSB', 'external_supplier'],
            ];

            foreach ($directSources as [$internalCode, $heading, $name, $category]) {
                $centre = $this->ensureCentre($centres, $internalCode, [
                    'name' => $name,
                    'ppk_id' => null,
                    'source_category' => $category,
                ]);
                $this->ensureGroup($groups, [
                    'heading' => $heading,
                    'source_category' => $category,
                    'weighbridge_centre_id' => $centre->id,
                ]);
            }
        });
    }

    private function ensureCentre(WeighbridgeCentre $centres, string $code, array $values): WeighbridgeCentre
    {
        $centre = $centres->newQuery()->firstOrCreate(
            ['code' => $code],
            array_merge($values, ['is_active' => true]),
        );

        if (! $centre->is_active || $centre->name !== $values['name']
            || $centre->ppk_id !== $values['ppk_id']
            || $centre->source_category !== $values['source_category']) {
            throw new DomainException("Conflicting canonical FFB source: {$code}. Review the existing master; it was not reassigned.");
        }

        return $centre;
    }

    private function ensureGroup(FfbSourceGroup $groups, array $values): FfbSourceGroup
    {
        $group = $groups->newQuery()
            ->where('normalized_heading', FfbSourceAlias::normalizeCode($values['heading']))
            ->firstOrCreate([], array_merge($values, ['is_active' => true]));

        if (! $group->is_active || $group->source_category !== $values['source_category']
            || $group->weighbridge_centre_id !== $values['weighbridge_centre_id']) {
            throw new DomainException("Conflicting FFB heading mapping: {$values['heading']}. Review is required.");
        }

        return $group;
    }

    private function ensureMapping(
        FfbSourceCodeMapping $mappings,
        int $groupId,
        string $code,
        int $centreId,
    ): void {
        $mapping = $mappings->newQuery()
            ->where('normalized_code', FfbSourceAlias::normalizeCode($code))
            ->firstOrCreate(
                ['ffb_source_group_id' => $groupId],
                ['code' => $code, 'weighbridge_centre_id' => $centreId, 'is_active' => true],
            );

        if (! $mapping->is_active || $mapping->weighbridge_centre_id !== $centreId) {
            throw new DomainException("Conflicting contextual FFB code: {$code}. Review is required.");
        }
    }
}
