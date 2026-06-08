<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendHtmlMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry policy. NOTE: $tries/$backoff only take effect when the job is
     * QUEUED via SendHtmlMailJob::dispatch() (e.g. tool requests, staff
     * account mail). Callers that use ::dispatchSync() - leave/vendor/
     * negotiation/feedback workflow mail - run the handler inline on the
     * current request and bypass the queue worker, so these properties do not
     * apply to them; those callers handle failures with their own try/catch.
     */
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly string $to,
        private readonly string $toName,
        private readonly string $subject,
        private readonly string $body,
        private readonly array  $cc = [],
        private readonly ?string $fromAddress = null,
        private readonly ?string $fromName = null,
        private readonly array $presentation = [],
    ) {}

    public function handle(): void
    {
        $htmlBody = view('emails.generic-html', [
            'subject' => $this->subject,
            'preheader' => $this->presentation['preheader']
                ?? Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($this->body))) ?: $this->subject, 140),
            'headerLabel' => $this->presentation['headerLabel'] ?? 'KIJO Notification',
            'headerTitle' => $this->presentation['headerTitle'] ?? $this->subject,
            'headerSubtitle' => $this->presentation['headerSubtitle'] ?? null,
            'footer' => $this->presentation['footer'] ?? null,
            'body' => $this->body,
        ])->render();

        Mail::html($htmlBody, function ($m) {
            $fromAddress = isset($this->fromAddress) ? $this->fromAddress : null;
            $fromName = isset($this->fromName) ? $this->fromName : null;

            if ($fromAddress) {
                $m->from($fromAddress, $fromName ?: null);
            }
            $m->to($this->to, $this->toName)->subject($this->subject);
            if (!empty($this->cc)) {
                $m->cc($this->cc);
            }
        });
    }
}
