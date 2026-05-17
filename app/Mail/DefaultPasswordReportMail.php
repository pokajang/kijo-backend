<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DefaultPasswordReportMail extends Mailable
{
    public function __construct(
        public int $count,
        public int $noticeSent,
        public int $noticeFailed,
        public array $rows,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Action Required: Users Still Using Default Password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.default-password-report');
    }
}
