<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
                'logs' => $this->recentDiagnosticLogs(),
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
            return $this->withLoggedDiagnostic($request, $configurationError);
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
            return $this->withLoggedDiagnostic($request, $configurationError);
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
            $responseData = [
                'type' => 'quote_pdf',
                'status' => 'failed',
                'mailer' => $mailer,
                'from' => $fromAddress,
                'expected_from' => self::QUOTE_FROM_ADDRESS,
                'to' => $data['recipient_email'],
                'attachment' => $attachmentName,
                'completed_at' => now()->toISOString(),
            ];

            return response()->json([
                'status' => 'error',
                'message' => 'Quote PDF diagnostic email failed. Check the quote SMTP configuration.',
                'data' => $responseData + [
                    'log' => $this->recordDiagnosticLog(
                        $request,
                        $responseData,
                        'Quote PDF diagnostic email failed. Check the quote SMTP configuration.',
                        $e
                    ),
                ],
            ], 500);
        }

        $this->auditLog->log($request, "Sent quote PDF diagnostic email to {$data['recipient_email']}");
        $responseData = [
            'type' => 'quote_pdf',
            'status' => 'sent',
            'mailer' => $mailer,
            'from' => $fromAddress,
            'expected_from' => self::QUOTE_FROM_ADDRESS,
            'to' => $data['recipient_email'],
            'attachment' => $attachmentName,
            'completed_at' => now()->toISOString(),
        ];

        return response()->json([
            'status' => 'success',
            'message' => 'Quote PDF diagnostic email sent.',
            'data' => $responseData + [
                'log' => $this->recordDiagnosticLog(
                    $request,
                    $responseData,
                    'Quote PDF diagnostic email sent.'
                ),
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
            $responseData = [
                'type' => str_contains($label, 'default') ? 'default' : 'mail',
                'status' => 'failed',
                'mailer' => $mailer,
                'from' => $fromAddress,
                'expected_from' => str_contains($label, 'default')
                    ? self::DEFAULT_FROM_ADDRESS
                    : null,
                'to' => $recipientEmail,
                'completed_at' => now()->toISOString(),
            ];
            $message = Str::ucfirst($label).' failed. Check SMTP configuration.';

            return response()->json([
                'status' => 'error',
                'message' => $message,
                'data' => $responseData + [
                    'log' => $this->recordDiagnosticLog($request, $responseData, $message, $e),
                ],
            ], 500);
        }

        $this->auditLog->log($request, 'Sent '.$label.' diagnostic email to '.$recipientEmail);
        $responseData = [
            'type' => str_contains($label, 'default') ? 'default' : 'mail',
            'status' => 'sent',
            'mailer' => $mailer,
            'from' => $fromAddress,
            'expected_from' => str_contains($label, 'default')
                ? self::DEFAULT_FROM_ADDRESS
                : null,
            'to' => $recipientEmail,
            'completed_at' => now()->toISOString(),
        ];

        return response()->json([
            'status' => 'success',
            'message' => Str::ucfirst($label).' sent.',
            'data' => $responseData + [
                'log' => $this->recordDiagnosticLog(
                    $request,
                    $responseData,
                    Str::ucfirst($label).' sent.'
                ),
            ],
        ]);
    }

    private function configurationError(
        string $mailer,
        string $fromAddress,
        string $label,
        ?string $expectedFromAddress = null,
        array $context = []
    ): ?JsonResponse {
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
            ! is_array($mailerConfig) ||
            ($nonLiveTransport && ! app()->environment('testing')) ||
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
                    'message' => "{$label} SMTP configuration is incomplete. Missing: ".implode(', ', $missingFields).'.',
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
    ): array {
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

    private function withLoggedDiagnostic(Request $request, JsonResponse $response): JsonResponse
    {
        $payload = $response->getData(true);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($data !== []) {
            $payload['data'] = $data + [
                'log' => $this->recordDiagnosticLog(
                    $request,
                    $data,
                    (string) ($payload['message'] ?? 'Mail diagnostic blocked.')
                ),
            ];
            $response->setData($payload);
        }

        return $response;
    }

    private function recordDiagnosticLog(
        Request $request,
        array $data,
        string $responseMessage,
        ?\Throwable $exception = null
    ): array {
        $mailer = (string) ($data['mailer'] ?? '');
        $completedAt = now();
        $insert = [
            'type' => (string) ($data['type'] ?? 'mail'),
            'status' => (string) ($data['status'] ?? 'failed'),
            'mailer' => $mailer !== '' ? $mailer : null,
            'transport' => $this->transportForMailer($mailer),
            'from_address' => $data['from'] ?? null,
            'expected_from_address' => $data['expected_from'] ?? null,
            'recipient_email' => (string) ($data['to'] ?? ''),
            'attachment_name' => $data['attachment'] ?? null,
            'response_message' => $responseMessage,
            'missing_config' => ! empty($data['missing_config']) ? json_encode($data['missing_config']) : null,
            'error_class' => $exception ? get_class($exception) : null,
            'staff_id' => $request->session()->get('staff_id') ?: null,
            'name_code' => mb_substr((string) $request->session()->get('name_code', ''), 0, 20) ?: null,
            'ip_address' => mb_substr($this->resolveClientIp($request), 0, 45),
            'completed_at' => $completedAt,
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ];

        try {
            $id = DB::table('mail_diagnostic_logs')->insertGetId($insert);
            $row = DB::table('mail_diagnostic_logs')->where('id', $id)->first();

            if ($row) {
                return $this->formatDiagnosticLog($row);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'id' => null,
            'type' => $insert['type'],
            'status' => $insert['status'],
            'mailer' => $insert['mailer'],
            'transport' => $insert['transport'],
            'from' => $insert['from_address'],
            'expected_from' => $insert['expected_from_address'],
            'to' => $insert['recipient_email'],
            'attachment' => $insert['attachment_name'],
            'response' => $insert['response_message'],
            'missing_config' => $data['missing_config'] ?? null,
            'completed_at' => $completedAt->toISOString(),
        ];
    }

    private function recentDiagnosticLogs(int $limit = 50): array
    {
        try {
            return DB::table('mail_diagnostic_logs')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row): array => $this->formatDiagnosticLog($row))
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    private function formatDiagnosticLog(object $row): array
    {
        $missingConfig = $row->missing_config ?? null;
        if (is_string($missingConfig) && $missingConfig !== '') {
            $decoded = json_decode($missingConfig, true);
            $missingConfig = is_array($decoded) ? $decoded : null;
        }

        return array_filter([
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'mailer' => $row->mailer,
            'transport' => $row->transport,
            'from' => $row->from_address,
            'expected_from' => $row->expected_from_address,
            'to' => $row->recipient_email,
            'attachment' => $row->attachment_name,
            'response' => $row->response_message,
            'missing_config' => $missingConfig,
            'error_class' => $row->error_class,
            'staff_id' => $row->staff_id !== null ? (int) $row->staff_id : null,
            'name_code' => $row->name_code,
            'ip_address' => $row->ip_address,
            'completed_at' => $this->timestampToIso($row->completed_at ?? null),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function timestampToIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function transportForMailer(string $mailer): ?string
    {
        $config = $mailer !== '' ? config("mail.mailers.{$mailer}") : null;

        return is_array($config) ? strtolower((string) ($config['transport'] ?? '')) ?: null : null;
    }

    private function resolveClientIp(Request $request): string
    {
        foreach (['CF-Connecting-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
            $value = $request->header($header);
            if (! $value) {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $ip = trim($part);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $request->ip() ?? 'UNKNOWN';
    }

    private function mailerDiagnostics(
        string $mailer,
        string $fromAddress,
        string $fromName,
        string $expectedFromAddress
    ): array {
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
        $options = new Options;
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
