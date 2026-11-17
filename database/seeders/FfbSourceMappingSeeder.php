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
 * Requires official PPK masters; the independent estate is an FFB parent, not an official PPK.
 * Conflicts abort rather than overwrite existing mappings.
 */
class FfbSourceMappingSeeder extends Seeder
{
    public const PPK_SOURCES = [
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
        ['S/TENGAH', 'SRC-S-TENGAH', 'PPK-SEGAMAT'],
        ['SIMPANG 5 /1', 'SRC-SIMPANG-5-1', 'PPK-SIMPANG-LIMA'],
        ['PPK KLUANG', 'SRC-PPK-KLUANG', 'PPK-KLUANG'],
    ];

    public const DIRECT_SOURCES = [
        ['SRC-LADANG-BUKIT-BUJANG', 'LADANG BUKIT BUJANG', 'Ladang Bukit Bujang', 'ppnj_owned'],
        ['SRC-LADANG-KAHANG-1', 'LADANG KAHANG 1', 'Ladang Kahang 1', 'ppnj_owned'],
        ['SRC-LADANG-KAHANG-2', 'LADANG KAHANG 2', 'Ladang Kahang 2', 'ppnj_owned'],
        ['SRC-LADANG-KONSORTIUM-PPNJ-PPK', 'LADANG KONSORTIUM PPNJ/PPK', 'Ladang Konsortium PPNJ/PPK', 'ppnj_owned'],
        ['SRC-USAHASAMA-JELAPANG-MEGAH', 'LADANG USAHASAMA PPNJ / JELAPANG MEGAH', 'Jelapang Megah', 'ppnj_owned'],
        ['SRC-USAHASAMA-PPK-KLUANG-UTARA', 'LADANG USAHASAMA PPNJ / PPK KLUANG UTARA', Ppk::KLUANG_UTARA_NAME, 'ppnj_ppk_integrated'],
        ['SRC-BANGAS-AGRO', 'BANGAS AGRO ENTERPRISE', 'BANGAS AGRO ENTERPRISE', 'external_supplier'],
        ['SRC-KOPERASI-SAWIT-MAMPAN-SEGAMAT', 'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD', 'KOPERASI PENANAM SAWIT MAMPAN SEGAMAT BERHAD', 'external_supplier'],
        ['SRC-MEWAH-MEGA', 'MEWAH MEGA OIL PALM SDN BHD', 'MEWAH MEGA OIL PALM SDN BHD', 'external_supplier'],
        ['SRC-BIS-JAYA', 'BIS JAYA TRADING SDN BHD', 'BIS JAYA TRADING SDN BHD', 'external_supplier'],
        ['SRC-GUAN-LENG', 'GUAN LENG TRADING SDN BHD', 'GUAN LENG TRADING SDN BHD', 'external_supplier'],
        ['SRC-HJ-MANSOR', 'HJ MANSOR FAMILY AND SONS SDN BHD', 'HJ MANSOR FAMILY AND SONS SDN BHD', 'external_supplier'],
        ['SRC-KAHANG-OIL-PALM', 'KAHANG OIL PALM TRADING', 'KAHANG OIL PALM TRADING', 'external_supplier'],
        ['SRC-USAHASAMA-PKAYIS-KDSSB', 'LADANG USAHASAMA PKAYIS&KDSSB', 'LADANG USAHASAMA PKAYIS&KDSSB', 'external_supplier'],
    ];

    public const CONTEXTUAL_SOURCES = [
        'SRC-LADANG-KONSORTIUM-PPNJ-PPK' => ['23A', '23B'],
        'SRC-USAHASAMA-PKAYIS-KDSSB' => ['99/A'],
    ];

    public const HEADING_SCOPED_CODES = [
        'SRC-BANGAS-AGRO' => ['BAE'],
        'SRC-USAHASAMA-JELAPANG-MEGAH' => ['B JELAPANG'],
        'SRC-USAHASAMA-PPK-KLUANG-UTARA' => ['01 B'],
    ];

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
                'PPK-SEGAMAT', 'PPK-KLUANG',
            ];
            $parents = [];

            foreach ($ppkCodes as $code) {
                $parent = $ppks->newQuery()->where('code', $code)->first();

                if (! $parent || ! $parent->is_active || $parent->entity_type !== Ppk::OFFICIAL_PPK) {
                    throw new DomainException("Missing or inactive PPK master: {$code}. Seed the approved PPK master first.");
                }

                $parents[$code] = $parent->id;
            }

            $independent = $ppks->newQuery()->where('code', Ppk::KLUANG_UTARA_CODE)->firstOrCreate([], [
                'code' => Ppk::KLUANG_UTARA_CODE,
                'name' => Ppk::KLUANG_UTARA_NAME,
                'entity_type' => Ppk::INDEPENDENT_INTEGRATED_ESTATE,
                'is_active' => true,
            ]);
            if (! $independent->is_active || $independent->name !== Ppk::KLUANG_UTARA_NAME
                || $independent->entity_type !== Ppk::INDEPENDENT_INTEGRATED_ESTATE) {
                throw new DomainException('Conflicting independent FFB parent: '.Ppk::KLUANG_UTARA_CODE);
            }

            $integratedGroup = $this->ensureGroup($groups, [
                'heading' => 'PERTUBUHAN PELADANG NEGERI JOHOR',
                'source_category' => 'ppnj_ppk_integrated',
                'weighbridge_centre_id' => null,
            ]);

            // Each distinct confirmed code remains a separate canonical source.
            foreach (self::PPK_SOURCES as [$sourceCode, $internalCode, $ppkCode]) {
                $centre = $this->ensureCentre($centres, $internalCode, [
                    'name' => $sourceCode,
                    'ppk_id' => $parents[$ppkCode],
                    'source_category' => 'ppnj_ppk_integrated',
                ]);
                $this->ensureMapping($mappings, $integratedGroup->id, $sourceCode, $centre->id);
            }

            foreach (self::DIRECT_SOURCES as [$internalCode, $heading, $name, $category]) {
                $centre = $this->ensureCentre($centres, $internalCode, [
                    'name' => $name,
                    'ppk_id' => $internalCode === 'SRC-USAHASAMA-PPK-KLUANG-UTARA' ? $independent->id : null,
                    'source_category' => $category,
                ]);
                $contextual = isset(self::CONTEXTUAL_SOURCES[$internalCode]);
                $group = $this->ensureGroup($groups, [
                    'heading' => $heading,
                    'source_category' => $category,
                    'weighbridge_centre_id' => $contextual ? null : $centre->id,
                ]);

                foreach (array_merge(
                    self::CONTEXTUAL_SOURCES[$internalCode] ?? [],
                    self::HEADING_SCOPED_CODES[$internalCode] ?? [],
                ) as $code) {
                    $this->ensureMapping($mappings, $group->id, $code, $centre->id);
                }

                if ($internalCode === 'SRC-BIS-JAYA') {
                    $alternateGroup = $this->ensureGroup($groups, [
                        'heading' => 'BIS JAYA TRADING SDN BHD (A)',
                        'source_category' => $category,
                        'weighbridge_centre_id' => null,
                    ]);
                    $this->ensureMapping($mappings, $alternateGroup->id, 'BIS JAYA (A)', $centre->id);
                }
            }

            $wakaf = $this->ensureCentre($centres, 'SRC-LADANG-WAKAF-JEMALUANG', [
                'name' => 'LADANG WAKAF JEMALUANG',
                'ppk_id' => null,
                'source_category' => 'ppnj_owned',
            ]);
            $wakafGroup = $this->ensureGroup($groups, [
                'heading' => 'LADANG WAKAF JEMALUANG',
                'source_category' => 'ppnj_owned',
                'weighbridge_centre_id' => null,
            ]);
            $this->ensureMapping($mappings, $wakafGroup->id, 'A+B+C', $wakaf->id);
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
