<?php

namespace Tests\Unit\Services;

use App\Models\Ppk;
use App\Services\FfbApprovedMasterPlan;
use Database\Seeders\PpkMasterSeeder;
use DomainException;
use PHPUnit\Framework\TestCase;

class FfbApprovedMasterPlanTest extends TestCase
{
    private const COUNTS = [
        'ppks' => 30, 'weighbridge_centres' => 31, 'ffb_source_aliases' => 0,
        'ffb_source_groups' => 17, 'ffb_source_code_mappings' => 24,
    ];

    public function test_empty_database_plan_lists_all_approved_insertions(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $plan = $planner->compare(array_fill_keys(array_keys(self::COUNTS), []));
        $this->assertSame(self::COUNTS, $plan['expected_final_counts']);
        $this->assertSame(self::COUNTS, array_map('count', $plan['inserts']));
        $this->assertCount(29, PpkMasterSeeder::OFFICIAL_PPKS);
        $this->assertContains(Ppk::KLUANG_UTARA_CODE, $plan['inserts']['ppks']);
        $this->assertContains('SRC-LADANG-KAHANG-1', $plan['inserts']['weighbridge_centres']);
        $this->assertSame('PPK-SIMPANG-LIMA', PpkMasterSeeder::canonicalCodeForName('PPK Simpang Lima Batu Pahat'));
        $spec = $planner->specification();
        $this->assertSame('ppnj_owned', $spec['weighbridge_centres']['SRC-LADANG-KONSORTIUM-PPNJ-PPK']['source_category']);
        $this->assertSame('external_supplier', $spec['weighbridge_centres']['SRC-USAHASAMA-PKAYIS-KDSSB']['source_category']);
        $this->assertSame(Ppk::KLUANG_UTARA_CODE, $spec['weighbridge_centres']['SRC-USAHASAMA-PPK-KLUANG-UTARA']['parent_code']);
        $this->assertArrayHasKey('LADANG USAHASAMA PKAYIS&KDSSB|99/A', $spec['ffb_source_code_mappings']);
        $this->assertArrayNotHasKey('LADANG USAHASAMA PKAYIS&KDSSB|99/B', $spec['ffb_source_code_mappings']);
    }

    public function test_exact_existing_records_and_nonsequential_ids_are_idempotent(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $rows = $this->rows($planner->specification());
        $plan = $planner->compare($rows);
        $this->assertSame(self::COUNTS, $plan['expected_final_counts']);
        $this->assertSame(array_fill_keys(array_keys(self::COUNTS), 0), array_map('count', $plan['inserts']));
        array_pop($rows['ffb_source_code_mappings']);
        $this->assertCount(1, $planner->compare($rows)['inserts']['ffb_source_code_mappings']);
    }

    public function test_existing_key_with_incorrect_parent_is_rejected(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $rows = $this->rows($planner->specification());
        $rows['weighbridge_centres'][0]->ppk_id = null;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Conflicting weighbridge_centres record: SRC-PT-RAJA-1');
        $planner->compare($rows);
    }

    public function test_wrong_heading_and_mapping_target_are_rejected(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $rows = $this->rows($planner->specification());
        $rows['ffb_source_groups'][0]->source_category = 'external_supplier';
        $rows['ffb_source_code_mappings'][0]->weighbridge_centre_id = $rows['weighbridge_centres'][1]->id;
        try {
            $planner->compare($rows);
            $this->fail('Conflicts must block the plan.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Conflicting ffb_source_groups', $exception->getMessage());
            $this->assertStringContainsString('Conflicting ffb_source_code_mappings', $exception->getMessage());
        }
    }

    public function test_inactive_or_misnamed_ppk_is_rejected(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $rows = $this->rows($planner->specification());
        $rows['ppks'][0]->name = 'Wrong name';
        $rows['ppks'][1]->is_active = 0;
        try {
            $planner->compare($rows);
            $this->fail('Conflicting official PPK records must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('PPK-BATU-ANAM', $exception->getMessage());
            $this->assertStringContainsString('PPK-SEGAMAT', $exception->getMessage());
        }
    }

    public function test_extra_fixture_records_and_global_alias_are_rejected(): void
    {
        $planner = new FfbApprovedMasterPlan();
        $rows = array_fill_keys(array_keys(self::COUNTS), []);
        $rows['weighbridge_centres'][] = (object) [
            'id' => 5, 'code' => 'STHVPOUKL', 'name' => 'Synthetic THVPOUKL', 'ppk_id' => null,
            'source_category' => 'ppnj_owned', 'is_active' => 1,
        ];
        $rows['ffb_source_groups'][] = (object) [
            'id' => 5, 'heading' => 'GROUP THVPOUKL', 'normalized_heading' => 'GROUP THVPOUKL',
            'source_category' => 'ppnj_owned', 'weighbridge_centre_id' => 5, 'is_active' => 1,
        ];
        $rows['ffb_source_aliases'][] = (object) ['id' => 3, 'normalized_code' => 'EXTRA'];
        try {
            $planner->compare($rows);
            $this->fail('Unexpected records must block the plan.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Unexpected weighbridge_centres record: STHVPOUKL', $exception->getMessage());
            $this->assertStringContainsString('Unexpected ffb_source_groups record: GROUP THVPOUKL', $exception->getMessage());
            $this->assertStringContainsString('Unexpected ffb_source_aliases record: EXTRA', $exception->getMessage());
        }
    }

    private function rows(array $spec): array
    {
        $rows = array_fill_keys(array_keys(self::COUNTS), []);
        $ppks = $centres = $groups = [];
        $id = 100;
        foreach ($spec['ppks'] as $code => $data) {
            $ppks[$code] = ++$id;
            $rows['ppks'][] = (object) (['id' => $id] + $data);
        }
        foreach ($spec['weighbridge_centres'] as $code => $data) {
            $centres[$code] = ++$id;
            $rows['weighbridge_centres'][] = (object) [
                'id' => $id, 'code' => $code, 'name' => $data['name'],
                'ppk_id' => $data['parent_code'] === null ? null : $ppks[$data['parent_code']],
                'source_category' => $data['source_category'], 'is_active' => 1,
            ];
        }
        foreach ($spec['ffb_source_groups'] as $heading => $data) {
            $groups[$heading] = ++$id;
            $rows['ffb_source_groups'][] = (object) [
                'id' => $id, 'heading' => $heading, 'normalized_heading' => $heading,
                'source_category' => $data['source_category'],
                'weighbridge_centre_id' => $data['centre_code'] === null ? null : $centres[$data['centre_code']],
                'is_active' => 1,
            ];
        }
        foreach ($spec['ffb_source_code_mappings'] as $data) {
            $rows['ffb_source_code_mappings'][] = (object) [
                'id' => ++$id, 'ffb_source_group_id' => $groups[$data['heading']],
                'code' => $data['code'], 'normalized_code' => $data['code'],
                'weighbridge_centre_id' => $centres[$data['centre_code']], 'is_active' => 1,
            ];
        }

        return $rows;
    }
}
