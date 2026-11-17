<?php

namespace App\Support;

use Illuminate\Support\Arr;

final class FfbReceivePresentation
{
    public const CATEGORY_LABELS = [
        'ppnj_owned' => 'Ladang Milik PPNJ',
        'ppnj_ppk_integrated' => 'BTS Bersepadu PPNJ–PPK',
        'external_supplier' => 'Pembekal Luar',
    ];

    public static function category(?string $category): string
    {
        return self::CATEGORY_LABELS[$category] ?? 'Belum diklasifikasikan';
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'uploaded' => 'Dimuat Naik',
            'needs_review' => 'Perlu Semakan',
            'ready' => 'Sedia untuk Semakan Pegawai',
            'failed' => 'Import Gagal',
            'confirmed' => 'Disahkan / Dikunci',
            'superseded' => 'Digantikan',
            'cancelled' => 'Dibatalkan',
            default => 'Status Tidak Dikenali',
        };
    }

    public static function mt(string|int|float|null $value): string
    {
        return $value === null ? '-' : number_format((float) $value, 2).' MT';
    }

    public static function message(string $message): string
    {
        return str_replace(
            ['calon tiket', 'Calon Tiket', 'ready'],
            ['tiket', 'Jumlah Tiket', self::status('ready')],
            $message,
        );
    }

    public static function ticketEvidence(array $rawValues, ?array $snapshot, ?string $category): array
    {
        // Hide unrelated parent metadata in the UI only; never change the stored snapshot.
        if ($category !== 'ppnj_ppk_integrated' && $snapshot !== null) {
            $snapshot = Arr::except($snapshot, ['ppk']);
            Arr::forget($snapshot, 'source.ppk_id');
        }

        return ['raw_values' => $rawValues, 'resolution_snapshot' => $snapshot];
    }
}
