<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;

class MonthlyDashboardReportMail extends Mailable
{
    public function __construct(
        public readonly string $reportMonth,
        public readonly string $periodLabel,
        public readonly string $downloadUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Year-to-Date Dashboard Management Report - {$this->reportMonth}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.monthly-dashboard-report',
            with: [
                'reportMonth' => $this->reportMonth,
                'periodLabel' => $this->periodLabel,
                'downloadUrl' => $this->downloadUrl,
            ],
        );
    }
}
