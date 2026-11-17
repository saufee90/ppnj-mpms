<?php

namespace Tests\Unit;

use App\Support\FfbReceivePresentation;
use PHPUnit\Framework\TestCase;

class FfbReceivePresentationTest extends TestCase
{
    public function test_mt_display_rounds_only_at_presentation_and_preserves_missing_values(): void
    {
        foreach ([
            '395.360000' => '395.36 MT',
            '1.234567' => '1.23 MT',
            '-1.235000' => '-1.24 MT',
            '0.000000' => '0.00 MT',
            '1000.000000' => '1,000.00 MT',
        ] as $value => $expected) {
            $this->assertSame($expected, FfbReceivePresentation::mt($value));
        }
        $this->assertSame('-', FfbReceivePresentation::mt(null));
    }

    public function test_categories_and_statuses_have_malay_display_labels(): void
    {
        foreach ([
            'ppnj_owned' => 'Ladang Milik PPNJ',
            'ppnj_ppk_integrated' => 'BTS Bersepadu PPNJ–PPK',
            'external_supplier' => 'Pembekal Luar',
        ] as $category => $label) {
            $this->assertSame($label, FfbReceivePresentation::category($category));
        }
        $this->assertSame('Belum diklasifikasikan', FfbReceivePresentation::category(null));
        foreach ([
            'uploaded' => 'Dimuat Naik',
            'needs_review' => 'Perlu Semakan',
            'ready' => 'Sedia untuk Semakan Pegawai',
            'failed' => 'Import Gagal',
            'confirmed' => 'Disahkan / Dikunci',
            'superseded' => 'Digantikan',
            'cancelled' => 'Dibatalkan',
        ] as $status => $label) {
            $this->assertSame($label, FfbReceivePresentation::status($status));
        }
        $this->assertSame(
            'Jumlah termasuk tiket pendua; Sedia untuk Semakan Pegawai bukan pengesahan.',
            FfbReceivePresentation::message('Jumlah termasuk calon tiket pendua; ready bukan pengesahan.'),
        );
    }

    public function test_parent_metadata_is_only_presented_for_integrated_tickets_without_mutating_snapshot(): void
    {
        $raw = ['K' => '1.234567'];
        $snapshot = ['ppk' => ['name' => 'Original parent'], 'source' => ['name' => 'Centre', 'ppk_id' => 3]];
        foreach (['ppnj_owned', 'external_supplier', null] as $category) {
            $evidence = FfbReceivePresentation::ticketEvidence($raw, $snapshot, $category);
            $this->assertSame($raw, $evidence['raw_values']);
            $this->assertSame(['source' => ['name' => 'Centre']], $evidence['resolution_snapshot']);
        }
        $this->assertSame($snapshot, FfbReceivePresentation::ticketEvidence($raw, $snapshot, 'ppnj_ppk_integrated')['resolution_snapshot']);
        $this->assertSame(3, $snapshot['source']['ppk_id']);
        $this->assertSame('Original parent', $snapshot['ppk']['name']);
    }
}
