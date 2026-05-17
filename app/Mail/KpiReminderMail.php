<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class KpiReminderMail extends Mailable
{
    public function __construct(public string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Monthly KPI Tracker Reminder');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kpi-reminder');
    }
}
