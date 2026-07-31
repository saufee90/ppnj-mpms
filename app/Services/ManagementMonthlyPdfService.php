<?php

namespace App\Services;

class ManagementMonthlyPdfService
{
    public function __construct(private readonly ManagementReportSvgChartService $chartService)
    {
    }

    public function generate(array $dataset, array $presentation): array
    {
        $charts = $this->chartService->build($dataset);
        $logoDataUri = $this->makeImageDataUri(public_path('images/logo-ppnj.jpg'));
        $filename = sprintf(
            'Laporan_Prestasi_Bulanan_%s_%s.pdf',
            str_replace(' ', '_', $dataset['meta']['scope_label']),
            str_replace(' ', '_', $dataset['meta']['period_label'])
        );

        $pdf = app('dompdf.wrapper')
            ->loadView('laporan-pengurusan-bulanan.pdf', compact(
                'dataset',
                'presentation',
                'charts',
                'logoDataUri'
            ))
            ->setPaper('a4', 'landscape');

        return [
            'content' => $pdf->output(),
            'filename' => $filename,
            'charts' => $charts,
        ];
    }

    private function makeImageDataUri(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }
}