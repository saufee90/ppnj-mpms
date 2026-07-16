<?php

namespace App\Services;

use App\Mail\DailyDashboardReport;
use App\Models\DailyOperation;
use App\Models\DailyReportNotification;
use App\Models\Mill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DailyReportNotificationService
{
    private const RECIPIENT_EMAIL = 'mohdfairul@ppnj.com.my';

    public function __construct(
        private DashboardPdfService $dashboardPdfService,
    ) {
    }

    public function sendIfReady(Carbon|string $reportDate): void
    {
        $reportDate = Carbon::parse($reportDate)->startOfDay();

        $requiredMillIds = Mill::query()
            ->whereIn('code', ['KHG', 'BBJ'])
            ->pluck('id');

        if ($requiredMillIds->count() !== 2) {
            Log::warning('MPS daily report email tidak dihantar: KHG atau BBJ tidak dijumpai.');
            return;
        }

        $completedMillCount = DailyOperation::query()
            ->whereDate('tarikh', $reportDate->toDateString())
            ->whereIn('mill_id', $requiredMillIds)
            ->distinct()
            ->count('mill_id');

        if ($completedMillCount !== 2) {
            return;
        }

        $notification = DailyReportNotification::firstOrCreate(
            [
                'report_date' => $reportDate->toDateString(),
                'channel' => 'email',
                'recipient' => self::RECIPIENT_EMAIL,
            ],
            [
                'status' => 'pending',
            ]
        );

        if ($notification->status === 'sent') {
            return;
        }

        try {
            $report = $this->dashboardPdfService->generate($reportDate);

            Mail::to(self::RECIPIENT_EMAIL)->send(
                new DailyDashboardReport(
                    $report['display_date'],
                    $report['content'],
                    $report['filename']
                )
            );

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $notification->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            Log::error('MPS daily report email gagal dihantar.', [
                'report_date' => $reportDate->toDateString(),
                'recipient' => self::RECIPIENT_EMAIL,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}