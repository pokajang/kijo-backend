<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DefaultPasswordNoticeMail extends Mailable
{
    public function __construct(public string $recipientName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Security Action Required: Change Your Password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.default-password-notice');
    }
}
