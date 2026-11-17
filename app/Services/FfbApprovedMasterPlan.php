<?php

namespace App\Services;

use App\Models\FfbSourceAlias;
use App\Models\Ppk;
use Database\Seeders\FfbSourceMappingSeeder;
use Database\Seeders\PpkMasterSeeder;
use DomainException;

final class FfbApprovedMasterPlan
{
    public function specification(): array
    {
        $spec = [
            'ppks' => [], 'weighbridge_centres' => [], 'ffb_source_aliases' => [],
            'ffb_source_groups' => [], 'ffb_source_code_mappings' => [],
        ];
        foreach (PpkMasterSeeder::OFFICIAL_PPKS as $ppk) {
            $spec['ppks'][$ppk['code']] = $ppk + ['entity_type' => Ppk::OFFICIAL_PPK, 'is_active' => 1];
        }
        $spec['ppks'][Ppk::KLUANG_UTARA_CODE] = [
            'code' => Ppk::KLUANG_UTARA_CODE, 'name' => Ppk::KLUANG_UTARA_NAME,
            'entity_type' => Ppk::INDEPENDENT_INTEGRATED_ESTATE, 'is_active' => 1,
        ];
        $heading = 'PERTUBUHAN PELADANG NEGERI JOHOR';
        $spec['ffb_source_groups'][$heading] = [
            'heading' => $heading, 'source_category' => 'ppnj_ppk_integrated', 'centre_code' => null, 'is_active' => 1,
        ];
        foreach (FfbSourceMappingSeeder::PPK_SOURCES as [$code, $source, $parent]) {
            $spec['weighbridge_centres'][$source] = [
                'code' => $source, 'name' => $code, 'parent_code' => $parent,
                'source_category' => 'ppnj_ppk_integrated', 'is_active' => 1,
            ];
            $spec['ffb_source_code_mappings'][$heading.'|'.$code] = [
                'heading' => $heading, 'code' => $code, 'centre_code' => $source, 'is_active' => 1,
            ];
        }
        foreach (FfbSourceMappingSeeder::DIRECT_SOURCES as [$code, $heading, $name, $category]) {
            $spec['weighbridge_centres'][$code] = [
                'code' => $code, 'name' => $name,
                'parent_code' => $code === 'SRC-USAHASAMA-PPK-KLUANG-UTARA' ? Ppk::KLUANG_UTARA_CODE : null,
                'source_category' => $category, 'is_active' => 1,
            ];
            $spec['ffb_source_groups'][$heading] = [
                'heading' => $heading, 'source_category' => $category,
                'centre_code' => isset(FfbSourceMappingSeeder::CONTEXTUAL_SOURCES[$code]) ? null : $code,
                'is_active' => 1,
            ];
            foreach (array_merge(
                FfbSourceMappingSeeder::CONTEXTUAL_SOURCES[$code] ?? [],
                FfbSourceMappingSeeder::HEADING_SCOPED_CODES[$code] ?? [],
            ) as $alias) {
                $spec['ffb_source_code_mappings'][$heading.'|'.$alias] = [
                    'heading' => $heading, 'code' => $alias, 'centre_code' => $code, 'is_active' => 1,
                ];
            }
        }
        $heading = 'BIS JAYA TRADING SDN BHD (A)';
        $spec['ffb_source_groups'][$heading] = [
            'heading' => $heading, 'source_category' => 'external_supplier', 'centre_code' => null, 'is_active' => 1,
        ];
        $spec['ffb_source_code_mappings'][$heading.'|BIS JAYA (A)'] = [
            'heading' => $heading, 'code' => 'BIS JAYA (A)', 'centre_code' => 'SRC-BIS-JAYA', 'is_active' => 1,
        ];
        $heading = 'LADANG WAKAF JEMALUANG';
        $spec['weighbridge_centres']['SRC-LADANG-WAKAF-JEMALUANG'] = [
            'code' => 'SRC-LADANG-WAKAF-JEMALUANG', 'name' => $heading, 'parent_code' => null,
            'source_category' => 'ppnj_owned', 'is_active' => 1,
        ];
        $spec['ffb_source_groups'][$heading] = [
            'heading' => $heading, 'source_category' => 'ppnj_owned', 'centre_code' => null, 'is_active' => 1,
        ];
        $spec['ffb_source_code_mappings'][$heading.'|A+B+C'] = [
            'heading' => $heading, 'code' => 'A+B+C', 'centre_code' => 'SRC-LADANG-WAKAF-JEMALUANG', 'is_active' => 1,
        ];

        return $spec;
    }

    /**
     * Compare database rows to the same canonical definitions used by the seeders.
     * IDs are used only to follow existing relationships, never as proposed IDs.
     */
    public function compare(array $rows): array
    {
        $spec = $this->specification();
        $ppks = $centres = $groups = [];
        foreach ($rows['ppks'] as $row) {
            $ppks[$row->id] = $row->code;
        }
        foreach ($rows['weighbridge_centres'] as $row) {
            $centres[$row->id] = $row->code;
        }
        foreach ($rows['ffb_source_groups'] as $row) {
            $groups[$row->id] = $row->normalized_heading;
        }

        $existing = array_fill_keys(array_keys($spec), []);
        $errors = [];
        foreach ($rows as $table => $records) {
            foreach ($records as $row) {
                $actual = match ($table) {
                    'ppks' => ['code' => $row->code, 'name' => $row->name, 'entity_type' => $row->entity_type, 'is_active' => (int) $row->is_active],
                    'weighbridge_centres' => ['code' => $row->code, 'name' => $row->name,
                        'parent_code' => $row->ppk_id === null ? null : ($ppks[$row->ppk_id] ?? '#missing:'.$row->ppk_id),
                        'source_category' => $row->source_category, 'is_active' => (int) $row->is_active],
                    'ffb_source_groups' => ['heading' => $row->heading, 'source_category' => $row->source_category,
                        'centre_code' => $row->weighbridge_centre_id === null ? null : ($centres[$row->weighbridge_centre_id] ?? '#missing:'.$row->weighbridge_centre_id),
                        'is_active' => (int) $row->is_active],
                    'ffb_source_code_mappings' => ['heading' => $groups[$row->ffb_source_group_id] ?? '#missing:'.$row->ffb_source_group_id,
                        'code' => $row->code, 'centre_code' => $centres[$row->weighbridge_centre_id] ?? '#missing:'.$row->weighbridge_centre_id,
                        'is_active' => (int) $row->is_active],
                    'ffb_source_aliases' => [],
                    default => throw new DomainException("Unknown master table: {$table}."),
                };
                $key = match ($table) {
                    'ppks', 'weighbridge_centres' => $row->code,
                    'ffb_source_groups' => $row->normalized_heading,
                    'ffb_source_code_mappings' => $actual['heading'].'|'.$row->normalized_code,
                    'ffb_source_aliases' => $row->normalized_code,
                };
                if (! isset($spec[$table][$key])) {
                    $errors[] = "Unexpected {$table} record: {$key} (id {$row->id})";
                } elseif (isset($existing[$table][$key]) || $actual !== $spec[$table][$key]
                    || ($table === 'ffb_source_groups' && FfbSourceAlias::normalizeCode($row->heading) !== $row->normalized_heading)
                    || ($table === 'ffb_source_code_mappings' && FfbSourceAlias::normalizeCode($row->code) !== $row->normalized_code)) {
                    $errors[] = "Conflicting {$table} record: {$key} (id {$row->id})";
                }
                $existing[$table][$key] = true;
            }
        }
        if ($errors !== []) {
            throw new DomainException("Master preflight failed:\n".implode("\n", $errors));
        }

        $inserts = $final = [];
        foreach ($spec as $table => $definitions) {
            $inserts[$table] = array_keys(array_diff_key($definitions, $existing[$table]));
            $final[$table] = count($definitions);
        }

        return ['inserts' => $inserts, 'expected_final_counts' => $final];
    }
}
