<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QuoteRecord\SendQuoteEmailRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class QuoteRecordEmailController extends Controller
{
    private const SUPPORTED_SERVICES = ['training', 'ih', 'manpower', 'special', 'equipment'];

    public function __construct(private AuditLogService $auditLog) {}

    public function send(SendQuoteEmailRequest $request, string $service, int $id): JsonResponse
    {
        $service = strtolower(trim($service));
        if (!in_array($service, self::SUPPORTED_SERVICES, true)) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported quote service.'], 404);
        }

        $mailer = (string) config('mail.quote.mailer', 'quote_smtp');
        $fromAddress = trim((string) config('mail.quote.from.address', ''));
        $fromName = trim((string) config('mail.quote.from.name', 'AMIOSH Admin'));
        if (
            $mailer === '' ||
            in_array($mailer, ['array', 'log'], true) ||
            $fromAddress === '' ||
            str_contains(strtolower($fromAddress), 'example.com') ||
            !is_array(config("mail.mailers.{$mailer}"))
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Quotation email sender is not configured yet. Set the quote SMTP mailer and sender address first.',
            ], 503);
        }

        $staffEmail = trim((string) $request->session()->get('email', ''));
        $staffName = trim((string) $request->session()->get('full_name', ''));
        if ($staffEmail === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your staff email is not available in the current session.',
            ], 422);
        }

        $quote = $this->findQuote($service, $id);
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }

        $recipientEmail = trim((string) ($quote->pic_email ?? ''));
        if ($recipientEmail === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'This quotation has no client recipient email.',
            ], 422);
        }

        $pdfResponse = $this->generatePdfResponse($request, $service, $id);
        if (!$pdfResponse || $pdfResponse->getStatusCode() !== 200) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unable to generate the quotation PDF attachment.',
            ], 500);
        }

        $pdfBinary = $pdfResponse->getContent();
        if (!is_string($pdfBinary) || $pdfBinary === '') {
            return response()->json([
                'status' => 'error',
                'message' => 'Generated quotation PDF is empty.',
            ], 500);
        }

        $attachmentName = $this->extractFilename(
            (string) $pdfResponse->headers->get('Content-Disposition', ''),
            $quote,
            $id
        );

        $subject = trim((string) $request->input('subject'));
        $body = trim((string) $request->input('body'));
        $serviceLabel = $this->serviceLabel($service);
        $recipientName = trim((string) ($quote->pic_name ?: ''));
        $recipientDisplay = $recipientName !== ''
            ? "{$recipientName} <{$recipientEmail}>"
            : $recipientEmail;
        $htmlBody = view('emails.quote-record-send', [
            'subject' => $subject,
            'fromAddress' => $fromAddress,
            'serviceLabel' => $serviceLabel,
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? "quote-{$id}"),
            'recipientDisplay' => $recipientDisplay,
            'attachmentName' => $attachmentName,
            'bodyParagraphs' => $this->formatBodyParagraphs($body),
        ])->render();

        try {
            Mail::mailer($mailer)->html($htmlBody, function ($message) use (
                $attachmentName,
                $fromAddress,
                $fromName,
                $pdfBinary,
                $quote,
                $recipientEmail,
                $staffEmail,
                $staffName,
                $subject
            ) {
                $recipientName = trim((string) ($quote->pic_name ?: $quote->client_name ?: ''));

                $message->from($fromAddress, $fromName)
                    ->to($recipientEmail, $recipientName !== '' ? $recipientName : null)
                    ->subject($subject)
                    ->replyTo($staffEmail, $staffName !== '' ? $staffName : null)
                    ->cc($staffEmail, $staffName !== '' ? $staffName : null)
                    ->attachData($pdfBinary, $attachmentName, ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'message' => 'System email sending failed. Check SMTP configuration and try again.',
            ], 500);
        }

        $quoteRef = (string) ($quote->quote_ref_no ?? "quote-{$id}");
        $this->auditLog->log(
            $request,
            "Sent {$service} quotation email {$quoteRef} to {$recipientEmail} with cc {$staffEmail}"
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Quotation email sent successfully.',
            'data' => [
                'service' => $service,
                'quote_id' => $id,
                'quote_ref_no' => $quoteRef,
                'to' => [
                    'name' => (string) ($quote->pic_name ?? ''),
                    'email' => $recipientEmail,
                ],
                'cc' => [
                    'name' => $staffName,
                    'email' => $staffEmail,
                ],
                'reply_to' => [
                    'name' => $staffName,
                    'email' => $staffEmail,
                ],
                'attachment' => $attachmentName,
            ],
        ]);
    }

    private function findQuote(string $service, int $id): ?object
    {
        $tableByService = [
            'training' => 'quotes_training',
            'ih' => 'quotes_ih',
            'manpower' => 'quotes_manpower',
            'special' => 'quotes_special',
            'equipment' => 'quotes_equipment',
        ];

        $table = $tableByService[$service] ?? null;
        if ($table === null) {
            return null;
        }

        return DB::table($table)
            ->where('id', $id)
            ->select(['id', 'quote_ref_no', 'client_name', 'pic_name', 'pic_email'])
            ->first();
    }

    private function generatePdfResponse(Request $request, string $service, int $quoteId): mixed
    {
        $pdfRequest = Request::create(
            $request->path(),
            'GET',
            ['quote_id' => $quoteId],
            $request->cookies->all(),
            [],
            $request->server->all()
        );
        $pdfRequest->setLaravelSession($request->session());

        return match ($service) {
            'training' => app(QuoteRecordTrainingSpecialController::class)->pdfTraining($pdfRequest),
            'special' => app(QuoteRecordTrainingSpecialController::class)->pdfSpecial($pdfRequest),
            'ih' => app(QuoteRecordController::class)->pdfIh($pdfRequest),
            'manpower' => app(QuoteRecordController::class)->pdfManpower($pdfRequest),
            'equipment' => app(QuoteRecordController::class)->pdfEquipment($pdfRequest),
            default => null,
        };
    }

    private function extractFilename(string $contentDisposition, object $quote, int $quoteId): string
    {
        if (
            preg_match('/filename\*?=(?:UTF-8\'\')?"?([^\";]+)"?/i', $contentDisposition, $matches)
            && !empty($matches[1])
        ) {
            return trim(rawurldecode($matches[1]), "\"'");
        }

        $safeRef = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($quote->quote_ref_no ?? "quote-{$quoteId}"));
        return ($safeRef !== '' ? $safeRef : "quote-{$quoteId}") . '.pdf';
    }

    private function serviceLabel(string $service): string
    {
        return match ($service) {
            'training' => 'Training',
            'ih' => 'Industrial Hygiene',
            'manpower' => 'Manpower Supply',
            'special' => 'Special',
            'equipment' => 'Equipment Supply',
            default => Str::title(str_replace(['-', '_'], ' ', $service)),
        };
    }

    private function formatBodyParagraphs(string $body): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $parts = preg_split("/\n{2,}/", $normalized) ?: [];

        return collect($parts)
            ->map(fn ($part) => trim($part))
            ->filter(fn ($part) => $part !== '')
            ->map(function (string $part) {
                $isNote = str_starts_with(strtolower($part), 'note:');
                $content = $isNote ? trim(substr($part, 5)) : $part;

                return [
                    'type' => $isNote ? 'note' : 'paragraph',
                    'html' => nl2br(e($content), false),
                ];
            })
            ->values()
            ->all();
    }
}
