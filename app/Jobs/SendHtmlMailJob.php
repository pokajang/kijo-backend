<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendHtmlMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    ) {}

    public function handle(): void
    {
        Mail::html($this->body, function ($m) {
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
