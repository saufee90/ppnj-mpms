<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyDashboardReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Carbon $reportDate,
        public string $pdfContent,
        public string $filename,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MPS Daily Report | ' . $this->reportDate->translatedFormat('d F Y'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.daily-dashboard-report',
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => $this->pdfContent,
                $this->filename
            )->withMime('application/pdf'),
        ];
    }
}