<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminMailDiagnosticsController extends Controller
{
    private const DEFAULT_FROM_ADDRESS = 'kijo@work.amiosh.com';
    private const QUOTE_FROM_ADDRESS = 'info.admin@amiosh.com';

    public function __construct(private AuditLogService $auditLog) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'default' => $this->mailerDiagnostics(
                    (string) config('mail.default', ''),
                    (string) config('mail.from.address', ''),
                    (string) config('mail.from.name', ''),
                    self::DEFAULT_FROM_ADDRESS
                ),
                'quote' => $this->mailerDiagnostics(
                    (string) config('mail.quote.mailer', 'quote_smtp'),
                    (string) config('mail.quote.from.address', ''),
                    (string) config('mail.quote.from.name', 'AMIOSH Admin'),
                    self::QUOTE_FROM_ADDRESS
                ),
            ],
        ]);
    }

    public function sendDefault(Request $request): JsonResponse
    {
        $data = $this->validateMailTest($request);
        $mailer = (string) config('mail.default', '');
        $fromAddress = trim((string) config('mail.from.address', ''));
        $fromName = trim((string) config('mail.from.name', 'KIJO'));

        $configurationError = $this->configurationError(
            $mailer,
            $fromAddress,
            'Default system email sender',
            self::DEFAULT_FROM_ADDRESS,
            [
                'type' => 'default',
                'to' => $data['recipient_email'],
            ]
        );
        if ($configurationError !== null) {
            return $configurationError;
        }

        $subject = 'KIJO default system email test';
        $htmlBody = $this->diagnosticHtml(
            'Default System Email Test',
            'This test verifies the default KIJO system sender.',
            $fromAddress
        );

        return $this->sendHtmlMessage(
            $request,
            $mailer,
            $fromAddress,
            $fromName,
            $data['recipient_email'],
            $subject,
            $htmlBody,
            'default system email'
        );
    }

    public function sendQuotePdf(Request $request): JsonResponse
    {
        $data = $this->validateMailTest($request);
        $mailer = (string) config('mail.quote.mailer', 'quote_smtp');
        $fromAddress = trim((string) config('mail.quote.from.address', ''));
        $fromName = trim((string) config('mail.quote.from.name', 'AMIOSH Admin'));
        $attachmentName = 'quote-mail-diagnostic.pdf';

        $configurationError = $this->configurationError(
            $mailer,
            $fromAddress,
            'Quotation email sender',
            self::QUOTE_FROM_ADDRESS,
            [
                'type' => 'quote_pdf',
                'to' => $data['recipient_email'],
                'attachment' => $attachmentName,
            ]
        );
        if ($configurationError !== null) {
            return $configurationError;
        }

        $subject = 'AMIOSH quote PDF email test';
        $pdfBinary = $this->diagnosticPdf($request);
        $htmlBody = $this->diagnosticHtml(
            'Quote PDF Email Test',
            'This test verifies the quotation sender and PDF attachment pipeline.',
            $fromAddress
        );

        try {
            Mail::mailer($mailer)->html($htmlBody, function ($message) use (
                $attachmentName,
                $fromAddress,
                $fromName,
                $pdfBinary,
                $request,
                $subject,
                $data
            ): void {
                $staffEmail = trim((string) $request->session()->get('email', ''));
                $staffName = trim((string) $request->session()->get('full_name', ''));

                $message->from($fromAddress, $fromName ?: null)
                    ->to($data['recipient_email'])
                    ->subject($subject)
                    ->attachData($pdfBinary, $attachmentName, ['mime' => 'application/pdf']);

                if ($staffEmail !== '') {
                    $message->replyTo($staffEmail, $staffName !== '' ? $staffName : null);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Quote PDF diagnostic email failed', ['exception' => $e]);

            return response()->json([
                'status' => 'error',
                'message' => 'Quote PDF diagnostic email failed. Check the quote SMTP configuration.',
                'data' => [
                    'type' => 'quote_pdf',
                    'status' => 'failed',
                    'mailer' => $mailer,
                    'from' => $fromAddress,
                    'expected_from' => self::QUOTE_FROM_ADDRESS,
                    'to' => $data['recipient_email'],
                    'attachment' => $attachmentName,
                    'completed_at' => now()->toISOString(),
                ],
            ], 500);
        }

        $this->auditLog->log($request, "Sent quote PDF diagnostic email to {$data['recipient_email']}");

        return response()->json([
            'status' => 'success',
            'message' => 'Quote PDF diagnostic email sent.',
            'data' => [
                'type' => 'quote_pdf',
                'status' => 'sent',
                'mailer' => $mailer,
                'from' => $fromAddress,
                'expected_from' => self::QUOTE_FROM_ADDRESS,
                'to' => $data['recipient_email'],
                'attachment' => $attachmentName,
                'completed_at' => now()->toISOString(),
            ],
        ]);
    }

    private function validateMailTest(Request $request): array
    {
        return $request->validate([
            'recipient_email' => ['required', 'email', 'max:255'],
        ]);
    }

    private function sendHtmlMessage(
        Request $request,
        string $mailer,
        string $fromAddress,
        string $fromName,
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        string $label
    ): JsonResponse {
        try {
            Mail::mailer($mailer)->html($htmlBody, function ($message) use (
                $fromAddress,
                $fromName,
                $recipientEmail,
                $subject
            ): void {
                $message->from($fromAddress, $fromName ?: null)
                    ->to($recipientEmail)
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error('Mail diagnostic email failed', ['label' => $label, 'exception' => $e]);

            return response()->json([
                'status' => 'error',
                'message' => Str::ucfirst($label) . ' failed. Check SMTP configuration.',
                'data' => [
                    'type' => str_contains($label, 'default') ? 'default' : 'mail',
                    'status' => 'failed',
                    'mailer' => $mailer,
                    'from' => $fromAddress,
                    'to' => $recipientEmail,
                    'completed_at' => now()->toISOString(),
                ],
            ], 500);
        }

        $this->auditLog->log($request, 'Sent ' . $label . ' diagnostic email to ' . $recipientEmail);

        return response()->json([
            'status' => 'success',
            'message' => Str::ucfirst($label) . ' sent.',
            'data' => [
                'type' => str_contains($label, 'default') ? 'default' : 'mail',
                'status' => 'sent',
                'mailer' => $mailer,
                'from' => $fromAddress,
                'expected_from' => str_contains($label, 'default')
                    ? self::DEFAULT_FROM_ADDRESS
                    : null,
                'to' => $recipientEmail,
                'completed_at' => now()->toISOString(),
            ],
        ]);
    }

    private function configurationError(
        string $mailer,
        string $fromAddress,
        string $label,
        ?string $expectedFromAddress = null,
        array $context = []
    ): ?JsonResponse
    {
        $mailerConfig = config("mail.mailers.{$mailer}");
        $transport = is_array($mailerConfig) ? strtolower((string) ($mailerConfig['transport'] ?? '')) : '';

        if (
            $expectedFromAddress !== null &&
            strcasecmp($fromAddress, $expectedFromAddress) !== 0
        ) {
            return response()->json([
                'status' => 'error',
                'message' => "{$label} is configured as {$fromAddress}, expected {$expectedFromAddress}.",
                'data' => $this->diagnosticResponseData(
                    $context,
                    'blocked',
                    $mailer,
                    $fromAddress,
                    $expectedFromAddress
                ),
            ], 503);
        }

        $nonLiveTransport = in_array($transport, ['array', 'log'], true);
        if (
            $mailer === '' ||
            !is_array($mailerConfig) ||
            ($nonLiveTransport && !app()->environment('testing')) ||
            $fromAddress === '' ||
            str_contains(strtolower($fromAddress), 'example.com')
        ) {
            return response()->json([
                'status' => 'error',
                'message' => "{$label} is not configured for live sending.",
                'data' => $this->diagnosticResponseData(
                    $context,
                    'blocked',
                    $mailer,
                    $fromAddress,
                    $expectedFromAddress
                ),
            ], 503);
        }

        if ($transport === 'smtp') {
            $missingFields = $this->missingSmtpConfigFields($mailerConfig);
            if ($missingFields !== []) {
                return response()->json([
                    'status' => 'error',
                    'message' => "{$label} SMTP configuration is incomplete. Missing: " . implode(', ', $missingFields) . '.',
                    'data' => $this->diagnosticResponseData(
                        $context + ['missing_config' => $missingFields],
                        'blocked',
                        $mailer,
                        $fromAddress,
                        $expectedFromAddress
                    ),
                ], 503);
            }
        }

        return null;
    }

    private function missingSmtpConfigFields(array $mailerConfig): array
    {
        $required = ['host', 'port', 'username', 'password'];

        return array_values(array_filter(
            $required,
            static fn (string $field): bool => trim((string) ($mailerConfig[$field] ?? '')) === ''
        ));
    }

    private function diagnosticResponseData(
        array $context,
        string $status,
        string $mailer,
        string $fromAddress,
        ?string $expectedFromAddress = null
    ): array
    {
        return array_filter([
            'type' => $context['type'] ?? null,
            'status' => $status,
            'mailer' => $mailer,
            'from' => $fromAddress,
            'expected_from' => $expectedFromAddress,
            'to' => $context['to'] ?? null,
            'attachment' => $context['attachment'] ?? null,
            'missing_config' => $context['missing_config'] ?? null,
            'completed_at' => now()->toISOString(),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function mailerDiagnostics(
        string $mailer,
        string $fromAddress,
        string $fromName,
        string $expectedFromAddress
    ): array
    {
        $config = config("mail.mailers.{$mailer}");
        $config = is_array($config) ? $config : [];
        $transport = strtolower((string) ($config['transport'] ?? ''));

        return [
            'mailer' => $mailer,
            'transport' => $transport,
            'host' => (string) ($config['host'] ?? ''),
            'port' => (string) ($config['port'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'live_ready' => $this->configurationError(
                $mailer,
                trim($fromAddress),
                'Mail sender',
                $expectedFromAddress
            ) === null,
        ];
    }

    private function diagnosticHtml(string $title, ?string $body, string $fromAddress): string
    {
        $message = trim((string) $body);
        if ($message === '') {
            $message = 'This is a KIJO mail diagnostic message.';
        }

        return view('emails.admin-mail-diagnostic', [
            'title' => $title,
            'body' => $message,
            'fromAddress' => $fromAddress,
            'sentAt' => now()->format('d M Y, h:i A'),
        ])->render();
    }

    private function diagnosticPdf(Request $request): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('pdf.admin-mail-diagnostic', [
            'generatedAt' => now()->format('d M Y, h:i A'),
            'generatedBy' => (string) ($request->session()->get('email', '') ?: 'System Admin'),
        ])->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
