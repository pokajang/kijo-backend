<?php

namespace App\Mail;

use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DefaultPasswordNoticeMail extends Mailable
{
    public string $loginUrl;

    public function __construct(public string $recipientName, ?string $loginUrl = null)
    {
        $this->loginUrl = $loginUrl ?? app(SystemEmailUrlBuilder::class)->frontendUrl('/login');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Security Action Required: Change Your Password');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.default-password-notice');
    }
}
