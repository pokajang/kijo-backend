<?php

namespace App\Services\Stats;

use App\Mail\MonthlyDashboardReportMail;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MonthlyDashboardReportService extends PdfRenderer
{
    private const STORAGE_DIR = 'dashboard-monthly-reports';
    private const SCHEDULE_SETTINGS_TABLE = 'dashboard_monthly_report_schedule_settings';
    private const EMAIL_LOGS_TABLE = 'dashboard_monthly_report_email_logs';
    private const SCHEDULE_UNITS = ['days', 'weeks', 'months'];

    public function __construct(
        private readonly StatsService $stats,
        private readonly WorkloadDashboardStatsService $workloadStats,
    ) {}

    public function export(Request $request)
    {
        $monthInput = trim((string) $request->query('month', ''));
        $month = $monthInput === '' ? $this->previousReportMonth() : $this->normalizeReportMonth($monthInput);
        if ($month === null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid report month. Use YYYY-MM.'], 422);
        }

        $force = $request->boolean('force');
        $record = $this->generateForMonth($month, $force, $request);

        if (! $record || ! $this->storedPdfExists((string) $record->stored_path)) {
            return response()->json(['status' => 'error', 'message' => 'Report PDF has not been generated.'], 404);
        }

        return $this->storedPdfResponse(
            (string) $record->stored_path,
            $this->downloadName($month),
            'inline'
        );
    }

    public function publicDownload(string $token)
    {
        if (! Schema::hasTable('dashboard_monthly_reports')) {
            return response()->json(['status' => 'error', 'message' => 'Report not found.'], 404);
        }

        $hash = hash('sha256', $token);
        $record = DB::table('dashboard_monthly_reports')
            ->where('public_token_hash', $hash)
            ->first();

        if (! $record || ! $record->public_token_expires_at) {
            return response()->json(['status' => 'error', 'message' => 'Report not found.'], 404);
        }

        if (Carbon::parse($record->public_token_expires_at)->isPast()) {
            return response()->json(['status' => 'error', 'message' => 'Report link has expired.'], 410);
        }

        if (! $this->storedPdfExists((string) $record->stored_path)) {
            return response()->json(['status' => 'error', 'message' => 'Report file not found.'], 404);
        }

        return $this->storedPdfResponse(
            (string) $record->stored_path,
            $this->downloadName((string) $record->report_month),
            'inline'
        );
    }

    public function generateForMonth(string $month, bool $force = false, ?Request $baseRequest = null): object
    {
        $month = $this->requireReportMonth($month);

        return $this->withReportLock('generate', $month, function () use ($month, $force, $baseRequest): object {
            $range = $this->monthRange($month);
            $path = self::STORAGE_DIR.'/'.$month.'.pdf';
            $existing = $this->reportRecord($month);

            if (
                ! $force
                && $existing
                && $this->storedPdfExists((string) $existing->stored_path)
                && in_array((string) $existing->status, ['generated', 'emailed'], true)
            ) {
                return $existing;
            }

            try {
                $startedAt = microtime(true);
                $payload = $this->buildPayload($month, $range['start'], $range['end'], $baseRequest);
                $payloadJson = $this->payloadJson($payload);
                $generatedAt = now();
                $html = view('pdf.monthly-dashboard-report', [
                    ...$payload,
                    'logoDataUri' => $this->companyLogoDataUri(),
                    'fontFaceCss' => $this->arialFontFaceCss(),
                ])->render();
                $dompdf = $this->renderPortraitWithFooter(
                    $html,
                    $generatedAt,
                    (string) ($baseRequest?->session()->get('name_code', 'SYSTEM') ?? 'SYSTEM'),
                    (string) ($baseRequest?->session()->get('staff_id', '0') ?? '0'),
                );

                Storage::disk('private')->put($path, $dompdf->output());

                DB::table('dashboard_monthly_reports')->updateOrInsert(
                    ['report_month' => $month],
                    [
                        'start_date' => $range['start'],
                        'end_date' => $range['end'],
                        'stored_path' => $path,
                        'generated_at' => $generatedAt,
                        'status' => 'generated',
                        'error_message' => null,
                        ...$this->reportPayloadMetadata($payloadJson, $startedAt),
                        'created_at' => $existing?->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            } catch (Throwable $exception) {
                report($exception);
                DB::table('dashboard_monthly_reports')->updateOrInsert(
                    ['report_month' => $month],
                    [
                        'start_date' => $range['start'],
                        'end_date' => $range['end'],
                        'stored_path' => $path,
                        'status' => 'failed',
                        'error_message' => Str::limit($exception->getMessage(), 1000, ''),
                        'created_at' => $existing?->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
                throw $exception;
            }

            return $this->reportRecord($month);
        });
    }

    public function sendForMonth(string $month, bool $force = false, ?Request $baseRequest = null): array
    {
        $record = $this->generateForMonth($month, $force, $baseRequest);
        $recipients = $this->configuredRecipients();

        if (empty($recipients)) {
            DB::table('dashboard_monthly_reports')
                ->where('id', $record->id)
                ->update([
                    'recipients_json' => json_encode([]),
                    'status' => 'generated',
                    'updated_at' => now(),
                ]);

            return ['sent' => 0, 'skipped' => true, 'url' => null];
        }

        return $this->sendRecordToRecipients($record, $recipients, 'production');
    }

    public function sendTestForMonth(string $month, array $recipients, bool $force = false, ?Request $baseRequest = null): array
    {
        if (empty($recipients)) {
            throw new \InvalidArgumentException('At least one test recipient is required.');
        }

        $record = $this->generateForMonth($month, $force, $baseRequest);

        return $this->sendRecordToRecipients($record, $recipients, 'test');
    }

    public function statusSummary(?string $month = null): array
    {
        $record = null;
        if (Schema::hasTable('dashboard_monthly_reports')) {
            $query = DB::table('dashboard_monthly_reports');
            $record = $month
                ? $query->where('report_month', $month)->first()
                : $query->orderByDesc('report_month')->first();
        }

        return [
            'configuredRecipientCount' => count($this->configuredRecipients()),
            'previousMonth' => $this->previousReportMonth(),
            'latestReport' => $this->recordSummary($record),
        ];
    }

    public function scheduleSettings(): array
    {
        if (! Schema::hasTable(self::SCHEDULE_SETTINGS_TABLE)) {
            return $this->formatScheduleSettings($this->defaultScheduleSettings(), false);
        }

        $row = DB::table(self::SCHEDULE_SETTINGS_TABLE)->where('id', 1)->first();
        if (! $row) {
            $settings = $this->defaultScheduleSettings();
            DB::table(self::SCHEDULE_SETTINGS_TABLE)->insert([
                'id' => 1,
                ...$settings,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table(self::SCHEDULE_SETTINGS_TABLE)->where('id', 1)->first();
        }

        return $this->formatScheduleSettings($row ?: $this->defaultScheduleSettings(), true);
    }

    public function updateScheduleSettings(array $data, ?Request $request = null): array
    {
        if (! Schema::hasTable(self::SCHEDULE_SETTINGS_TABLE)) {
            throw new \RuntimeException('dashboard_monthly_report_schedule_settings table is missing.');
        }

        $enabled = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $intervalValue = max(1, min(365, (int) ($data['interval_value'] ?? $data['intervalValue'] ?? 1)));
        $intervalUnit = (string) ($data['interval_unit'] ?? $data['intervalUnit'] ?? 'months');
        $startDate = (string) ($data['start_date'] ?? $data['startDate'] ?? now()->toDateString());
        $sendTime = (string) ($data['send_time'] ?? $data['sendTime'] ?? '08:30');

        if (! in_array($intervalUnit, self::SCHEDULE_UNITS, true)) {
            throw new \InvalidArgumentException('Schedule interval unit must be days, weeks, or months.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            throw new \InvalidArgumentException('Schedule start date must use YYYY-MM-DD.');
        }

        if (! preg_match('/^\d{2}:\d{2}$/', $sendTime)) {
            throw new \InvalidArgumentException('Schedule send time must use HH:MM.');
        }

        [$hour, $minute] = array_map('intval', explode(':', $sendTime));
        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException('Schedule send time must be a valid 24-hour time.');
        }

        $settings = [
            'enabled' => $enabled ?? true,
            'interval_value' => $intervalValue,
            'interval_unit' => $intervalUnit,
            'start_date' => CarbonImmutable::parse($startDate, (string) config('app.timezone'))->toDateString(),
            'send_time' => sprintf('%02d:%02d', $hour, $minute),
        ];
        $nextSendAt = $settings['enabled'] ? $this->nextScheduledSendAt($settings) : null;

        $existing = DB::table(self::SCHEDULE_SETTINGS_TABLE)->where('id', 1)->first();
        DB::table(self::SCHEDULE_SETTINGS_TABLE)->updateOrInsert(
            ['id' => 1],
            [
                ...$settings,
                'next_send_at' => $nextSendAt?->toDateTimeString(),
                'updated_by_staff_id' => $request?->session()->get('staff_id') ?: null,
                'updated_by_code' => substr((string) ($request?->session()->get('name_code', '') ?? ''), 0, 20) ?: null,
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]
        );

        return $this->scheduleSettings();
    }

    public function runScheduledSend(?Request $baseRequest = null): array
    {
        $lock = null;
        try {
            $lock = Cache::lock('dashboard-monthly-report:scheduled-send', 600);
            if (! $lock->get()) {
                return ['due' => false, 'skipped' => true, 'reason' => 'schedule_locked', 'settings' => $this->scheduleSettings()];
            }
        } catch (Throwable $exception) {
            report($exception);
            $lock = null;
        }

        try {
            if (! Schema::hasTable(self::SCHEDULE_SETTINGS_TABLE)) {
                return ['due' => false, 'skipped' => true, 'reason' => 'settings_table_missing', 'settings' => $this->scheduleSettings()];
            }

            $settings = $this->scheduleSettings();
            if (! (bool) $settings['enabled']) {
                return ['due' => false, 'skipped' => true, 'reason' => 'disabled', 'settings' => $settings];
            }

            $nextSendAt = $settings['nextSendAt'] ? CarbonImmutable::parse($settings['nextSendAt']) : null;
            $now = CarbonImmutable::now((string) config('app.timezone'));
            if (! $nextSendAt || $nextSendAt->greaterThan($now)) {
                return ['due' => false, 'skipped' => true, 'reason' => 'not_due', 'settings' => $settings];
            }

            $month = $this->previousReportMonth();
            DB::table(self::SCHEDULE_SETTINGS_TABLE)
                ->where('id', 1)
                ->update([
                    'last_attempt_at' => now(),
                    'last_status' => 'running',
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            try {
                $result = $this->sendForMonth($month, false, $baseRequest);
                $next = $this->nextScheduledSendAt($settings, $now);
                DB::table(self::SCHEDULE_SETTINGS_TABLE)
                    ->where('id', 1)
                    ->update([
                        'next_send_at' => $next?->toDateTimeString(),
                        'last_sent_at' => empty($result['skipped']) ? now() : null,
                        'last_status' => empty($result['skipped']) ? 'sent' : 'skipped',
                        'last_error' => empty($result['skipped']) ? null : 'No configured recipients.',
                        'updated_at' => now(),
                    ]);

                return [
                    ...$result,
                    'due' => true,
                    'reportMonth' => $month,
                    'settings' => $this->scheduleSettings(),
                ];
            } catch (Throwable $exception) {
                $next = $this->nextScheduledSendAt($settings, $now);
                DB::table(self::SCHEDULE_SETTINGS_TABLE)
                    ->where('id', 1)
                    ->update([
                        'next_send_at' => $next?->toDateTimeString(),
                        'last_status' => 'failed',
                        'last_error' => Str::limit($exception->getMessage(), 1000, ''),
                        'updated_at' => now(),
                    ]);

                throw $exception;
            }
        } finally {
            $lock?->release();
        }
    }

    public function parseRecipientList(string $raw, bool $strict = false): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $rows = [];
        $invalid = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $name = '';
            $email = $part;
            if (preg_match('/^(.*?)<([^>]+)>$/', $part, $matches)) {
                $name = trim($matches[1], " \t\n\r\0\x0B\"'");
                $email = trim($matches[2]);
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $part;
                continue;
            }

            $rows[strtolower($email)] = ['name' => $name, 'email' => $email];
        }

        if ($strict && ! empty($invalid)) {
            throw new \InvalidArgumentException('Invalid recipient entry: '.implode(', ', $invalid));
        }

        return array_values($rows);
    }

    public function buildPayloadForDryRun(string $month): array
    {
        $month = $this->requireReportMonth($month);
        $range = $this->monthRange($month);

        return $this->buildPayload($month, $range['start'], $range['end'], null);
    }

    public function previousReportMonth(): string
    {
        return CarbonImmutable::now((string) config('app.timezone'))
            ->subMonthNoOverflow()
            ->format('Y-m');
    }

    public function configuredRecipients(): array
    {
        return $this->parseRecipientList((string) config('dashboard_reports.monthly_recipients', ''), false);
    }

    private function sendRecordToRecipients(object $record, array $recipients, string $sendType): array
    {
        return $this->withReportLock('send', (string) $record->report_month, function () use ($record, $recipients, $sendType): array {
            $record = $this->reportRecord((string) $record->report_month) ?: $record;
            $token = Str::random(64);
            $expiresAt = now()->addDays(max(1, (int) config('dashboard_reports.public_link_ttl_days', 90)));
            DB::table('dashboard_monthly_reports')
                ->where('id', $record->id)
                ->update([
                    'public_token_hash' => hash('sha256', $token),
                    'public_token_expires_at' => $expiresAt,
                    'recipients_json' => json_encode($recipients),
                    'updated_at' => now(),
                ]);

            $url = url('stats/monthly-dashboard-report/public/'.$token);
            $periodLabel = $this->periodLabel((string) $record->start_date, (string) $record->end_date);
            $sent = 0;
            $failures = [];

            foreach ($recipients as $recipient) {
                try {
                    Mail::to($recipient['email'], $recipient['name'] ?: null)
                        ->send(new MonthlyDashboardReportMail((string) $record->report_month, $periodLabel, $url));
                    $sent++;
                    $this->insertEmailLog($record, $recipient, $sendType, 'sent', $url, $expiresAt);
                } catch (Throwable $exception) {
                    report($exception);
                    $failures[] = $exception;
                    $this->insertEmailLog(
                        $record,
                        $recipient,
                        $sendType,
                        'failed',
                        $url,
                        $expiresAt,
                        $exception->getMessage()
                    );
                }
            }

            if ($failures !== []) {
                $message = Str::limit($failures[0]->getMessage(), 1000, '');
                DB::table('dashboard_monthly_reports')
                    ->where('id', $record->id)
                    ->update([
                        'status' => 'failed',
                        'error_message' => $message,
                        'updated_at' => now(),
                    ]);

                throw $failures[0];
            }

            DB::table('dashboard_monthly_reports')
                ->where('id', $record->id)
                ->update([
                    'email_sent_at' => now(),
                    'status' => 'emailed',
                    'error_message' => null,
                    'updated_at' => now(),
                ]);

            return [
                'sent' => $sent,
                'skipped' => false,
                'url' => $url,
                'publicTokenExpiresAt' => $expiresAt->toDateTimeString(),
                'report' => $this->recordSummary($this->reportRecord((string) $record->report_month)),
            ];
        });
    }

    private function buildPayload(string $month, string $startDate, string $endDate, ?Request $baseRequest): array
    {
        $request = $this->statsRequest($startDate, $endDate, $baseRequest);
        $sales = $this->salesPayload($request);
        $crm = $this->crmPayload($request);
        $financial = $this->financialPayload($request, $startDate, $endDate);
        $monitoring = $this->monitoringPayload($request);
        $workload = $this->workloadPayload($request);
        $staffPerformanceRows = $this->staffPerformanceRows($sales, $crm, $monitoring, $workload);

        return [
            'title' => 'Year-to-Date Dashboard Management Report',
            'reportMonth' => $month,
            'periodLabel' => $this->periodLabel($startDate, $endDate),
            'generatedAtLabel' => now()->format('d M Y, h:i A'),
            'summaryCards' => $this->summaryCards($sales, $crm, $financial, $monitoring, $workload),
            'decisionSummary' => $this->managementDecisionSummary($sales, $crm, $financial, $monitoring, $workload, $staffPerformanceRows),
            'staffPerformanceRows' => $staffPerformanceRows,
            'sales' => $sales,
            'crm' => $crm,
            'financial' => $financial,
            'monitoring' => $monitoring,
            'workload' => $workload,
        ];
    }

    private function salesPayload(Request $request): array
    {
        $monthly = $this->responseData(fn () => $this->stats->monthlySales($request));
        $byService = $this->responseData(fn () => $this->stats->awardedValueByService($request));
        $byPerson = $this->responseData(fn () => $this->stats->awardedValueByPerson($request));
        $bySource = $this->responseData(fn () => $this->stats->awardedValueBySource($request));
        $convStaff = $this->responseData(fn () => $this->stats->conversionRateByStaff($request));
        $convSource = $this->responseData(fn () => $this->stats->conversionRateBySource($request));
        $convService = $this->responseData(fn () => $this->stats->conversionRateByService($request));
        $salesRows = $monthly['monthlySales'] ?? [];

        return [
            'totalAwarded' => $this->sum($salesRows, 'amount'),
            'awardedCount' => $this->sum($salesRows, 'count'),
            'byService' => $this->rankRows($byService['awardValueByService'] ?? [], 'serviceGroup', 'awardedValue', 5, 'money'),
            'byPerson' => $this->rankRows($byPerson['awardValueByPerson'] ?? [], 'staffName', 'totalAwarded', 5, 'money', 'staffCode'),
            'bySource' => $this->rankRows($bySource['awardValueBySource'] ?? [], 'sourceName', 'awardedValue', 5, 'money'),
            'conversionStaff' => $this->conversionRows($convStaff['conversionRateByStaff'] ?? [], 'staffName', 'staffCode'),
            'conversionSource' => $this->conversionRows($convSource['conversionRateBySource'] ?? [], 'sourceName'),
            'conversionService' => $this->conversionRows($convService['conversionRateByService'] ?? [], 'serviceGroup'),
            'staffAwarded' => $this->staffAwardedRows($byPerson['awardValueByPerson'] ?? []),
            'staffConversion' => $this->staffConversionRows($convStaff['conversionRateByStaff'] ?? []),
        ];
    }

    private function crmPayload(Request $request): array
    {
        $quoteCount = $this->responseData(fn () => $this->stats->monthlyQuoteCount($request));
        $quoteValue = $this->responseData(fn () => $this->stats->monthlyQuoteValue($request));
        $quoteCountByPerson = $this->responseData(fn () => $this->stats->quoteCountByPerson($request));
        $quoteValueByPerson = $this->responseData(fn () => $this->stats->quoteValueByPerson($request));
        $quoteValueByService = $this->responseData(fn () => $this->stats->quoteValueByService($request));
        $monthlyQuoteValueByService = $this->responseData(fn () => $this->stats->monthlyQuoteValueByService($request));
        $inquiryCount = $this->responseData(fn () => $this->stats->inquiryStats($request));
        $inquiryValue = $this->responseData(fn () => $this->stats->inquiryStatsByValues($request));

        return [
            'quoteCount' => $this->sum($quoteCount['monthlyQuoteCount'] ?? [], 'count'),
            'quoteValue' => $this->sum($quoteValue['monthlyQuoteValue'] ?? [], 'amount'),
            'monthlyQuoteTrend' => $this->monthlyQuoteTrendRows(
                $quoteCount['monthlyQuoteCount'] ?? [],
                $quoteValue['monthlyQuoteValue'] ?? []
            ),
            'quoteActivityByStaff' => $this->quoteActivityRows(
                $quoteCountByPerson['quoteCountByPerson'] ?? [],
                $quoteValueByPerson['quoteValueByPerson'] ?? []
            ),
            'quoteValueByService' => $this->rankRows($quoteValueByService['quoteValueByService'] ?? [], 'serviceGroup', 'totalValue', 5, 'money'),
            'monthlyQuoteServiceRows' => $this->monthlyQuoteServiceRows($monthlyQuoteValueByService['monthlyStats'] ?? []),
            'inquirySourceMix' => $this->inquiryRows($inquiryCount['inquiryStats'] ?? [], $inquiryValue['inquiryStatsByValues'] ?? []),
            'staffQuotes' => $this->staffQuoteRows(
                $quoteCountByPerson['quoteCountByPerson'] ?? [],
                $quoteValueByPerson['quoteValueByPerson'] ?? []
            ),
        ];
    }

    private function financialPayload(Request $request, string $startDate, string $endDate): array
    {
        $income = $this->responseData(fn () => $this->stats->monthlyIncomeStatement($request));
        $trendStart = Carbon::parse($endDate)->subMonthsNoOverflow(5)->startOfMonth();
        $periodStart = Carbon::parse($startDate);
        if ($trendStart->lt($periodStart)) {
            $trendStart = $periodStart;
        }
        $trendRequest = $this->statsRequest(
            $trendStart->toDateString(),
            $endDate,
            $request
        );
        $trend = $this->responseData(fn () => $this->stats->monthlyInvoicedReceivedTrend($trendRequest));
        $debtors = $this->responseData(fn () => $this->stats->allDebtors($request));

        return [
            'totalInvoiced' => (float) ($income['totalInvoiced'] ?? 0),
            'totalReceived' => (float) ($income['totalReceived'] ?? 0),
            'outstandingAmount' => (float) ($income['outstandingAmount'] ?? 0),
            'outstandingCount' => (int) ($income['outstandingCount'] ?? 0),
            'uninvoicedAwardedAmount' => (float) ($income['uninvoicedAwardedAmount'] ?? 0),
            'uninvoicedAwardedCount' => (int) ($income['uninvoicedAwardedCount'] ?? 0),
            'outstandingSummary' => $this->money($income['outstandingAmount'] ?? 0).' across '.$this->unitCount($income['outstandingCount'] ?? 0, 'invoice'),
            'uninvoicedAwardedSummary' => $this->money($income['uninvoicedAwardedAmount'] ?? 0).' across '.$this->unitCount($income['uninvoicedAwardedCount'] ?? 0, 'item'),
            'trend' => array_map(fn ($row) => [
                'month' => (string) ($row['month'] ?? ''),
                'invoiced' => $this->money($row['invoiced'] ?? 0),
                'received' => $this->money($row['received'] ?? 0),
                'netMovement' => $this->money($row['netMovement'] ?? 0),
            ], array_slice($trend['monthlyInvoicedReceivedTrend'] ?? [], -6)),
            'debtors' => array_map(fn ($row) => [
                'client' => (string) ($row['client_name'] ?? '-'),
                'invoice' => (string) ($row['invoice_ref_no'] ?? '-'),
                'date' => (string) ($row['invoice_date'] ?? '-'),
                'amountRaw' => (float) ($row['grand_total'] ?? 0),
                'amount' => $this->money($row['grand_total'] ?? 0),
                'pic' => (string) ($row['internal_pic_code'] ?? '-'),
            ], array_slice($debtors['debtors'] ?? [], 0, 10)),
        ];
    }

    private function monitoringPayload(Request $request): array
    {
        $tools = $this->responseData(fn () => $this->stats->monitoringPipelineTools($request));
        $status = $this->responseData(fn () => $this->stats->monitoringPipelineStatus($request));
        $matrix = $this->responseData(fn () => $this->stats->monitoringStaffPipelineMatrix($request));
        $trends = $this->responseData(fn () => $this->stats->monitoringTrends($request));

        return [
            'currentTotalRm' => (float) ($tools['currentTotalRm'] ?? $status['totals']['totalRm'] ?? 0),
            'trendRows' => $this->monitoringTrendRows($trends['series'] ?? []),
            'pipelineStages' => array_map(fn ($row) => [
                'label' => (string) ($row['label'] ?? '-'),
                'total' => $this->count($row['total'] ?? 0),
                'individualQty' => $this->count($row['individualQty'] ?? 0),
                'specialProjectQty' => $this->count($row['specialProjectQty'] ?? 0),
                'tenderQty' => $this->count($row['tenderQty'] ?? 0),
            ], $tools['rows'] ?? []),
            'serviceRevenue' => array_map(fn ($row) => [
                'label' => (string) ($row['label'] ?? '-'),
                'qty' => $this->count($row['totalQty'] ?? 0),
                'rm' => $this->money($row['totalRm'] ?? 0),
            ], array_slice($status['rows'] ?? [], 0, 10)),
            'staffMatrix' => $this->monitoringStaffMatrixRows($matrix['rows'] ?? []),
            'staffRows' => $this->monitoringStaffRows($matrix['rows'] ?? []),
        ];
    }

    private function workloadPayload(Request $request): array
    {
        try {
            $payload = $this->workloadStats->workloadPayload($request);
        } catch (Throwable $exception) {
            report($exception);
            $payload = [];
        }

        $staffRows = $payload['staff'] ?? [];
        $staff = array_slice($staffRows, 0, 10);
        $history = $this->responseData(fn () => $this->stats->workloadHistory($request));

        return [
            'asOfDate' => (string) ($payload['asOfDate'] ?? ''),
            'staffCount' => count($staffRows),
            'totalActiveTasks' => $this->sum($staffRows, 'activeTasks'),
            'totalOverdueTasks' => $this->sum($staffRows, 'overdueTasks'),
            'topStaff' => array_map(fn ($row) => [
                'staff' => (string) ($row['staffLabel'] ?? $row['staffName'] ?? '-'),
                'scoreRaw' => (float) ($row['score'] ?? 0),
                'score' => $this->count($row['score'] ?? 0),
                'activeTasksRaw' => (float) ($row['activeTasks'] ?? 0),
                'activeTasks' => $this->count($row['activeTasks'] ?? 0),
                'overdueTasksRaw' => (float) ($row['overdueTasks'] ?? 0),
                'overdueTasks' => $this->count($row['overdueTasks'] ?? 0),
                'dueSoonTasksRaw' => (float) ($row['dueSoonTasks'] ?? 0),
                'dueSoonTasks' => $this->count($row['dueSoonTasks'] ?? 0),
            ], $staff),
            'historyRows' => $this->workloadHistoryRows($history['staff'] ?? []),
            'staffRows' => $this->workloadStaffRows($staffRows),
        ];
    }

    private function summaryCards(array $sales, array $crm, array $financial, array $monitoring, array $workload): array
    {
        return [
            ['label' => 'YTD Awarded Sales Value', 'value' => $this->money($sales['totalAwarded'] ?? 0), 'detail' => $this->unitCount($sales['awardedCount'] ?? 0, 'awarded sales item')],
            ['label' => 'YTD Quotation Value', 'value' => $this->money($crm['quoteValue'] ?? 0), 'detail' => $this->unitCount($crm['quoteCount'] ?? 0, 'quotation').' issued'],
            ['label' => 'YTD Payment Received', 'value' => $this->money($financial['totalReceived'] ?? 0), 'detail' => $this->money($financial['totalInvoiced'] ?? 0).' invoiced'],
            ['label' => 'Outstanding Receivables', 'value' => $this->money($financial['outstandingAmount'] ?? 0), 'detail' => $this->unitCount($financial['outstandingCount'] ?? 0, 'outstanding invoice')],
            ['label' => 'Active Workload Items', 'value' => $this->count($workload['totalActiveTasks'] ?? 0), 'detail' => $this->unitCount($workload['totalOverdueTasks'] ?? 0, 'overdue item')],
        ];
    }

    private function managementDecisionSummary(array $sales, array $crm, array $financial, array $monitoring, array $workload, array $staffRows): array
    {
        $totalInvoiced = (float) ($financial['totalInvoiced'] ?? 0);
        $totalReceived = (float) ($financial['totalReceived'] ?? 0);
        $outstanding = (float) ($financial['outstandingAmount'] ?? 0);
        $uninvoiced = (float) ($financial['uninvoicedAwardedAmount'] ?? 0);
        $quoteValue = (float) ($crm['quoteValue'] ?? 0);
        $awarded = (float) ($sales['totalAwarded'] ?? 0);
        $collectionRate = $totalInvoiced > 0 ? ($totalReceived / $totalInvoiced) * 100 : 0.0;
        $quoteToAwardedRatio = $awarded > 0 ? $quoteValue / $awarded : 0.0;

        $topAwardedService = $sales['byService'][0] ?? null;
        $topQuoteService = $crm['quoteValueByService'][0] ?? null;
        $topStaff = $sales['byPerson'][0] ?? null;
        $topDebtor = $financial['debtors'][0] ?? null;
        $topWorkload = $workload['topStaff'][0] ?? null;
        $bestConversion = $this->topStaffConversion($staffRows);

        $cashSignals = [
            [
                'label' => 'Collection rate',
                'value' => number_format($collectionRate, 1).'%',
                'detail' => $this->money($totalReceived).' received from '.$this->money($totalInvoiced).' invoiced',
            ],
            [
                'label' => 'Receivables exposure',
                'value' => $this->money($outstanding),
                'detail' => $this->unitCount($financial['outstandingCount'] ?? 0, 'outstanding invoice'),
            ],
            [
                'label' => 'Awarded but not invoiced',
                'value' => $this->money($uninvoiced),
                'detail' => $this->unitCount($financial['uninvoicedAwardedCount'] ?? 0, 'uninvoiced item'),
            ],
        ];

        $pipelineSignals = [
            [
                'label' => 'Quotation pipeline',
                'value' => $this->money($quoteValue),
                'detail' => $this->unitCount($crm['quoteCount'] ?? 0, 'quotation').' issued YTD',
            ],
            [
                'label' => 'Awarded sales',
                'value' => $this->money($awarded),
                'detail' => $this->unitCount($sales['awardedCount'] ?? 0, 'awarded sales item'),
            ],
            [
                'label' => 'Quote-to-awarded coverage',
                'value' => $awarded > 0 ? number_format($quoteToAwardedRatio, 1).'x' : '-',
                'detail' => 'Quotation value compared with awarded sales value',
            ],
        ];

        $driverSignals = [
            [
                'label' => 'Top awarded service',
                'value' => (string) ($topAwardedService['label'] ?? '-'),
                'detail' => (string) ($topAwardedService['value'] ?? $this->money(0)),
            ],
            [
                'label' => 'Top quote service',
                'value' => (string) ($topQuoteService['label'] ?? '-'),
                'detail' => (string) ($topQuoteService['value'] ?? $this->money(0)),
            ],
            [
                'label' => 'Top commercial staff',
                'value' => (string) ($topStaff['label'] ?? '-'),
                'detail' => (string) ($topStaff['value'] ?? $this->money(0)),
            ],
        ];

        $decisionPoints = [
            $outstanding > 0
                ? 'Collections: prioritize '.$this->money($outstanding).' outstanding receivables'.($topDebtor ? ', led by '.$topDebtor['client'].' / '.$topDebtor['invoice'].'.' : '.')
                : 'Collections: no outstanding receivable exposure is shown for the period.',
            $uninvoiced > 0
                ? 'Billing: convert '.$this->money($uninvoiced).' awarded work into invoices.'
                : 'Billing: no uninvoiced awarded amount is shown for the period.',
            ((float) ($workload['totalOverdueTasks'] ?? 0)) > 0
                ? 'Workload: clear '.$this->unitCount($workload['totalOverdueTasks'] ?? 0, 'overdue item').($topWorkload ? '; highest visible workload is '.$topWorkload['staff'].'.' : '.')
                : 'Workload: no overdue workload item is shown in the current snapshot.',
            $quoteValue > 0 && $awarded <= 0
                ? 'Pipeline: quotations exist but no awarded sales value is shown; validate conversion follow-up.'
                : 'Pipeline: compare top quotation services with awarded services to decide where follow-up should concentrate.',
            $bestConversion
                ? 'Performance: use '.$bestConversion['staff'].' conversion pattern as a benchmark for active quote follow-up.'
                : 'Performance: no staff conversion benchmark is available for the period.',
        ];

        $opportunities = [
            $topQuoteService
                ? 'Develop the top quoted service line: '.$topQuoteService['label'].' at '.$topQuoteService['value'].'.'
                : 'Build quotation activity so service-line demand can be compared.',
            $topAwardedService
                ? 'Protect the strongest awarded service line: '.$topAwardedService['label'].' at '.$topAwardedService['value'].'.'
                : 'Build awarded sales volume so service-line contribution is visible.',
            ((float) ($monitoring['currentTotalRm'] ?? 0)) > 0
                ? 'Convert monitoring pipeline value of '.$this->money($monitoring['currentTotalRm']).' through proposal, negotiation, and closed stages.'
                : 'Use monitoring pipeline capture to expose upcoming realized revenue.',
        ];

        return [
            'cashSignals' => $cashSignals,
            'pipelineSignals' => $pipelineSignals,
            'driverSignals' => $driverSignals,
            'decisionPoints' => array_slice($decisionPoints, 0, 5),
            'opportunities' => array_slice($opportunities, 0, 3),
        ];
    }

    private function topStaffConversion(array $staffRows): ?array
    {
        $eligible = array_values(array_filter($staffRows, fn ($row): bool => (float) ($row['conversionRateRaw'] ?? 0) > 0));
        if ($eligible === []) {
            return null;
        }

        usort($eligible, fn ($left, $right) => (float) ($right['conversionRateRaw'] ?? 0) <=> (float) ($left['conversionRateRaw'] ?? 0));
        $row = $eligible[0];

        return [
            'staff' => (string) ($row['staff'] ?? '-'),
            'rate' => number_format((float) ($row['conversionRateRaw'] ?? 0), 1).'%',
        ];
    }

    private function quoteActivityRows(array $counts, array $values): array
    {
        $valueByCode = [];
        foreach ($values as $row) {
            $key = strtoupper((string) ($row['staffCode'] ?? $row['staffName'] ?? ''));
            $valueByCode[$key] = (float) ($row['totalValue'] ?? 0);
        }

        $rows = array_map(function ($row) use ($valueByCode): array {
            $key = strtoupper((string) ($row['staffCode'] ?? $row['staffName'] ?? ''));
            return [
                'label' => trim((string) ($row['staffCode'] ?? '').' - '.(string) ($row['staffName'] ?? ''), ' -') ?: '-',
                'count' => $this->count($row['quoteCount'] ?? 0),
                'value' => $this->money($valueByCode[$key] ?? 0),
                'sort' => (int) ($row['quoteCount'] ?? 0),
            ];
        }, $counts);

        usort($rows, fn ($a, $b) => $b['sort'] <=> $a['sort']);

        $rows = array_values(array_slice($rows, 0, 5));

        return array_map(function ($row, $index) {
            unset($row['sort']);

            return ['rank' => $index + 1] + $row;
        }, $rows, array_keys($rows));
    }

    private function inquiryRows(array $counts, array $values): array
    {
        $valueBySource = [];
        foreach ($values as $row) {
            $valueBySource[(string) ($row['source'] ?? '')] = (float) ($row['totalValue'] ?? 0);
        }

        $rows = array_slice(array_map(fn ($row) => [
            'label' => (string) ($row['source'] ?? '-'),
            'count' => $this->count($row['count'] ?? 0),
            'value' => $this->money($valueBySource[(string) ($row['source'] ?? '')] ?? 0),
        ], $counts), 0, 5);

        $rows = array_values($rows);

        return array_map(
            fn ($row, $index) => ['rank' => $index + 1] + $row,
            $rows,
            array_keys($rows)
        );
    }

    private function monthlyQuoteTrendRows(array $counts, array $values): array
    {
        $rows = [];
        foreach ($counts as $row) {
            $month = (string) ($row['month'] ?? '');
            if ($month === '') {
                continue;
            }
            $rows[$month] = [
                'month' => $this->monthLabel($month),
                'count' => $this->count($row['count'] ?? 0),
                'value' => $this->money(0),
            ];
        }

        foreach ($values as $row) {
            $month = (string) ($row['month'] ?? '');
            if ($month === '') {
                continue;
            }
            $rows[$month] ??= [
                'month' => $this->monthLabel($month),
                'count' => '0',
                'value' => $this->money(0),
            ];
            $rows[$month]['value'] = $this->money($row['amount'] ?? 0);
        }

        ksort($rows);

        return array_values($rows);
    }

    private function monthlyQuoteServiceRows(array $rows): array
    {
        usort($rows, fn ($left, $right) => (float) ($right['totalValue'] ?? 0) <=> (float) ($left['totalValue'] ?? 0));
        $rows = array_values(array_slice($rows, 0, 8));

        return array_map(fn ($row, $index) => [
            'rank' => $index + 1,
            'service' => (string) ($row['serviceGroup'] ?? '-'),
            'monthsWithQuotes' => $this->count(count(array_filter($row['monthlyValues'] ?? [], fn ($value) => (float) $value > 0))),
            'value' => $this->money($row['totalValue'] ?? 0),
        ], $rows, array_keys($rows));
    }

    private function rankRows(array $rows, string $labelKey, string $valueKey, int $limit, string $format, ?string $prefixKey = null): array
    {
        usort($rows, fn ($a, $b) => (float) ($b[$valueKey] ?? 0) <=> (float) ($a[$valueKey] ?? 0));

        $rankedRows = array_values(array_slice($rows, 0, $limit));

        return array_map(fn ($row, $index) => [
            'rank' => $index + 1,
            'label' => trim((string) ($prefixKey ? ($row[$prefixKey] ?? '').' - ' : '').($row[$labelKey] ?? ''), ' -') ?: '-',
            'value' => $format === 'money' ? $this->money($row[$valueKey] ?? 0) : $this->count($row[$valueKey] ?? 0),
        ], $rankedRows, array_keys($rankedRows));
    }

    private function conversionRows(array $rows, string $labelKey, ?string $prefixKey = null): array
    {
        $rows = array_values(array_slice($rows, 0, 5));

        return array_map(fn ($row, $index) => [
            'rank' => $index + 1,
            'label' => trim((string) ($prefixKey ? ($row[$prefixKey] ?? '').' - ' : '').($row[$labelKey] ?? ''), ' -') ?: '-',
            'rate' => number_format((float) ($row['conversionRate'] ?? 0), 1).'%',
            'convertedCount' => $this->count($row['convertedCount'] ?? 0),
            'totalQuotes' => $this->count($row['totalQuotes'] ?? 0),
        ], $rows, array_keys($rows));
    }

    private function monitoringTrendRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'month' => (string) ($row['monthLabel'] ?? $this->monthLabel((string) ($row['month'] ?? ''))),
            'proposalQty' => $this->count($row['proposalQty'] ?? 0),
            'proposalRm' => $this->money($row['proposalRm'] ?? 0),
            'revenueQty' => $this->count($row['revenueQty'] ?? 0),
            'revenueRm' => $this->money($row['revenueRm'] ?? 0),
            'winRate' => number_format((float) ($row['winRate'] ?? 0), 1).'%',
        ], $rows);
    }

    private function monitoringStaffMatrixRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'staff' => (string) ($row['staffLabel'] ?? '-'),
            'leads' => $this->count($row['stages']['LEADS'] ?? 0),
            'qualified' => $this->count($row['stages']['QUALIFIED'] ?? 0),
            'meetingPitching' => $this->count($row['stages']['MEETING/ PITCHING'] ?? 0),
            'proposals' => $this->count($row['stages']['PROPOSAL'] ?? 0),
            'negotiation' => $this->count($row['stages']['NEGOTIATION'] ?? 0),
            'closed' => $this->count($row['stages']['CLOSED'] ?? 0),
            'individualQty' => $this->count($row['segments']['individual']['qty'] ?? 0),
            'individualRm' => $this->money($row['segments']['individual']['rm'] ?? 0),
            'specialProjectQty' => $this->count($row['segments']['specialProject']['qty'] ?? 0),
            'specialProjectRm' => $this->money($row['segments']['specialProject']['rm'] ?? 0),
            'tenderQty' => $this->count($row['segments']['tender']['qty'] ?? 0),
            'tenderRm' => $this->money($row['segments']['tender']['rm'] ?? 0),
            'revenue' => $this->money(
                ($row['segments']['individual']['rm'] ?? 0)
                + ($row['segments']['specialProject']['rm'] ?? 0)
                + ($row['segments']['tender']['rm'] ?? 0)
            ),
        ], array_slice($rows, 0, 10));
    }

    private function staffAwardedRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'key' => $this->staffKey($row['staffCode'] ?? null, $row['staffName'] ?? null),
            'staff' => $this->staffLabel($row['staffCode'] ?? null, $row['staffName'] ?? null),
            'awardedSalesValueRaw' => (float) ($row['totalAwarded'] ?? 0),
            'awardedSalesValue' => $this->money($row['totalAwarded'] ?? 0),
        ], $rows);
    }

    private function staffConversionRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'key' => $this->staffKey($row['staffCode'] ?? null, $row['staffName'] ?? null),
            'staff' => $this->staffLabel($row['staffCode'] ?? null, $row['staffName'] ?? null),
            'convertedQuotes' => $this->count($row['convertedCount'] ?? 0),
            'totalQuotes' => $this->count($row['totalQuotes'] ?? 0),
            'conversionRateRaw' => (float) ($row['conversionRate'] ?? 0),
            'conversionRate' => number_format((float) ($row['conversionRate'] ?? 0), 1).'%',
        ], $rows);
    }

    private function staffQuoteRows(array $counts, array $values): array
    {
        $rows = [];
        foreach ($counts as $row) {
            $key = $this->staffKey($row['staffCode'] ?? null, $row['staffName'] ?? null);
            $rows[$key] = [
                'key' => $key,
                'staff' => $this->staffLabel($row['staffCode'] ?? null, $row['staffName'] ?? null),
                'quotationCount' => $this->count($row['quoteCount'] ?? 0),
                'quotationValueRaw' => 0.0,
                'quotationValue' => $this->money(0),
            ];
        }

        foreach ($values as $row) {
            $key = $this->staffKey($row['staffCode'] ?? null, $row['staffName'] ?? null);
            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'key' => $key,
                    'staff' => $this->staffLabel($row['staffCode'] ?? null, $row['staffName'] ?? null),
                    'quotationCount' => '0',
                    'quotationValueRaw' => 0.0,
                    'quotationValue' => $this->money(0),
                ];
            }
            $rows[$key]['quotationValueRaw'] = (float) ($row['totalValue'] ?? 0);
            $rows[$key]['quotationValue'] = $this->money($row['totalValue'] ?? 0);
        }

        return array_values($rows);
    }

    private function monitoringStaffRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'key' => $this->staffKey(null, $row['staffLabel'] ?? null),
            'staff' => (string) ($row['staffLabel'] ?? '-'),
            'leadItems' => $this->count($row['stages']['LEADS'] ?? 0),
            'proposalItems' => $this->count($row['stages']['PROPOSAL'] ?? 0),
            'closedItems' => $this->count($row['stages']['CLOSED'] ?? 0),
            'realizedRevenueRaw' => (float) (
                ($row['segments']['individual']['rm'] ?? 0)
                + ($row['segments']['specialProject']['rm'] ?? 0)
                + ($row['segments']['tender']['rm'] ?? 0)
            ),
            'realizedRevenue' => $this->money(
                ($row['segments']['individual']['rm'] ?? 0)
                + ($row['segments']['specialProject']['rm'] ?? 0)
                + ($row['segments']['tender']['rm'] ?? 0)
            ),
        ], $rows);
    }

    private function workloadStaffRows(array $rows): array
    {
        return array_map(fn ($row) => [
            'key' => $this->staffKey(null, $row['staffLabel'] ?? $row['staffName'] ?? null),
            'staff' => (string) ($row['staffLabel'] ?? $row['staffName'] ?? '-'),
            'workloadScore' => $this->count($row['score'] ?? 0),
            'activeItems' => $this->count($row['activeTasks'] ?? 0),
            'overdueItems' => $this->count($row['overdueTasks'] ?? 0),
            'dueSoonItems' => $this->count($row['dueSoonTasks'] ?? 0),
        ], $rows);
    }

    private function workloadHistoryRows(array $rows): array
    {
        $mapped = array_map(function ($row): array {
            $points = array_values(array_filter(
                $row['points'] ?? [],
                fn ($point) => isset($point['score']) && is_numeric($point['score'])
            ));
            $scores = array_map(fn ($point) => (float) $point['score'], $points);
            $first = $scores[0] ?? 0;
            $latest = $scores !== [] ? $scores[count($scores) - 1] : 0;

            return [
                'staff' => $this->staffLabel($row['staffCode'] ?? null, $row['staffName'] ?? null),
                'snapshots' => $this->count(count($points)),
                'firstScore' => $this->count($first),
                'latestScore' => $this->count($latest),
                'averageScore' => $this->count($scores === [] ? 0 : array_sum($scores) / count($scores)),
                'peakScore' => $this->count($scores === [] ? 0 : max($scores)),
                'sort' => $latest,
            ];
        }, $rows);

        usort($mapped, fn ($left, $right) => (float) ($right['sort'] ?? 0) <=> (float) ($left['sort'] ?? 0));
        $mapped = array_values(array_slice($mapped, 0, 10));

        return array_map(function ($row): array {
            unset($row['sort']);

            return $row;
        }, $mapped);
    }

    private function staffPerformanceRows(array $sales, array $crm, array $monitoring, array $workload): array
    {
        $rows = [];

        foreach ($sales['staffAwarded'] ?? [] as $row) {
            $target = &$this->staffPerformanceRow($rows, $row['key'], $row['staff']);
            $target['awardedSalesValue'] = $row['awardedSalesValue'];
            $target['sortValue'] += (float) ($row['awardedSalesValueRaw'] ?? 0);
            unset($target);
        }

        foreach ($crm['staffQuotes'] ?? [] as $row) {
            $target = &$this->staffPerformanceRow($rows, $row['key'], $row['staff']);
            $target['quotationCount'] = $row['quotationCount'];
            $target['quotationValue'] = $row['quotationValue'];
            $target['sortValue'] += (float) ($row['quotationValueRaw'] ?? 0);
            unset($target);
        }

        foreach ($sales['staffConversion'] ?? [] as $row) {
            $target = &$this->staffPerformanceRow($rows, $row['key'], $row['staff']);
            $target['convertedQuotes'] = $row['convertedQuotes'];
            $target['totalQuotes'] = $row['totalQuotes'];
            $target['conversionRateRaw'] = $row['conversionRateRaw'];
            $target['conversionRate'] = $row['conversionRate'];
            unset($target);
        }

        foreach ($monitoring['staffRows'] ?? [] as $row) {
            $target = &$this->staffPerformanceRow($rows, $row['key'], $row['staff']);
            $target['leadItems'] = $row['leadItems'];
            $target['proposalItems'] = $row['proposalItems'];
            $target['closedItems'] = $row['closedItems'];
            $target['realizedRevenue'] = $row['realizedRevenue'];
            $target['sortValue'] += (float) ($row['realizedRevenueRaw'] ?? 0);
            unset($target);
        }

        foreach ($workload['staffRows'] ?? [] as $row) {
            $target = &$this->staffPerformanceRow($rows, $row['key'], $row['staff']);
            $target['workloadScore'] = $row['workloadScore'];
            $target['activeItems'] = $row['activeItems'];
            $target['overdueItems'] = $row['overdueItems'];
            $target['dueSoonItems'] = $row['dueSoonItems'];
            unset($target);
        }

        usort($rows, fn ($left, $right) => strcasecmp((string) $left['staff'], (string) $right['staff']));

        return array_map(function ($row) {
            unset($row['key'], $row['sortValue']);

            return $row;
        }, $rows);
    }

    private function &staffPerformanceRow(array &$rows, string $key, string $staff): array
    {
        if (! isset($rows[$key])) {
            $staffNameKey = $this->staffNameKey($staff);
            foreach (array_keys($rows) as $existingKey) {
                if ($staffNameKey !== '' && $this->staffNameKey((string) ($rows[$existingKey]['staff'] ?? '')) === $staffNameKey) {
                    return $rows[$existingKey];
                }
            }

            $rows[$key] = [
                'key' => $key,
                'staff' => $staff,
                'awardedSalesValue' => $this->money(0),
                'awardedSalesCount' => '-',
                'quotationCount' => '0',
                'quotationValue' => $this->money(0),
                'convertedQuotes' => '0',
                'totalQuotes' => '0',
                'conversionRateRaw' => 0.0,
                'conversionRate' => '-',
                'leadItems' => '0',
                'proposalItems' => '0',
                'closedItems' => '0',
                'realizedRevenue' => $this->money(0),
                'workloadScore' => '-',
                'activeItems' => '-',
                'overdueItems' => '-',
                'dueSoonItems' => '-',
                'sortValue' => 0.0,
            ];
        }

        return $rows[$key];
    }

    private function staffKey(?string $code, ?string $label): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code !== '') {
            return $code;
        }

        $label = strtoupper(trim((string) $label));
        if (str_contains($label, ' - ')) {
            $candidate = trim((string) strtok($label, '-'));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $label !== '' ? $label : 'UNASSIGNED';
    }

    private function staffLabel(?string $code, ?string $name): string
    {
        return trim(trim((string) $code).' - '.trim((string) $name), ' -') ?: '-';
    }

    private function staffNameKey(string $staff): string
    {
        $staff = trim($staff);
        if (str_contains($staff, ' - ')) {
            $parts = explode(' - ', $staff, 2);
            $staff = trim((string) ($parts[1] ?? $staff));
        }

        return strtoupper(preg_replace('/\s+/', ' ', $staff) ?? '');
    }

    private function statsRequest(string $startDate, string $endDate, ?Request $baseRequest): Request
    {
        $request = Request::create('/stats/monthly-dashboard-report', 'GET', [
            'period' => 'previousMonth',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $session = $baseRequest?->hasSession() ? $baseRequest->session() : app('session')->driver();
        $session->put('roles', $session->get('roles', ['System Admin']));
        $session->put('name_code', $session->get('name_code', 'SYSTEM'));
        $session->put('staff_id', $session->get('staff_id', 0));
        $request->setLaravelSession($session);
        if ($baseRequest) {
            $request->headers->replace($baseRequest->headers->all());
        }

        return $request;
    }

    private function responseData(callable $callback): array
    {
        try {
            $response = $callback();
            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);
                return is_array($data) && ($data['status'] ?? 'success') !== 'error' ? $data : [];
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return [];
    }

    private function withReportLock(string $scope, string $month, callable $callback): mixed
    {
        try {
            $lock = Cache::lock("dashboard-monthly-report:{$scope}:{$month}", 600);
        } catch (Throwable $exception) {
            report($exception);

            return $callback();
        }

        try {
            return $lock->block(10, $callback);
        } catch (LockTimeoutException $exception) {
            throw new \RuntimeException("Another dashboard report {$scope} is already running for {$month}.", 0, $exception);
        }
    }

    private function payloadJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function reportPayloadMetadata(string $payloadJson, float $startedAt): array
    {
        if (! Schema::hasTable('dashboard_monthly_reports')) {
            return [];
        }

        $metadata = [];
        if (Schema::hasColumn('dashboard_monthly_reports', 'payload_json')) {
            $metadata['payload_json'] = $payloadJson;
        }
        if (Schema::hasColumn('dashboard_monthly_reports', 'payload_hash')) {
            $metadata['payload_hash'] = hash('sha256', $payloadJson);
        }
        if (Schema::hasColumn('dashboard_monthly_reports', 'generation_duration_ms')) {
            $metadata['generation_duration_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        }

        return $metadata;
    }

    private function insertEmailLog(
        object $record,
        array $recipient,
        string $sendType,
        string $status,
        string $url,
        Carbon $expiresAt,
        ?string $errorMessage = null
    ): void {
        if (! Schema::hasTable(self::EMAIL_LOGS_TABLE)) {
            return;
        }

        $now = now();
        DB::table(self::EMAIL_LOGS_TABLE)->insert([
            'report_id' => isset($record->id) ? (int) $record->id : null,
            'report_month' => (string) $record->report_month,
            'recipient_email' => (string) ($recipient['email'] ?? ''),
            'recipient_name' => (string) ($recipient['name'] ?? '') ?: null,
            'send_type' => $sendType,
            'status' => $status,
            'public_url' => $url,
            'public_token_expires_at' => $expiresAt,
            'error_message' => $errorMessage ? Str::limit($errorMessage, 1000, '') : null,
            'sent_at' => $status === 'sent' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function recordSummary(?object $record): ?array
    {
        if (! $record) {
            return null;
        }

        $recipients = json_decode((string) ($record->recipients_json ?? '[]'), true);
        if (! is_array($recipients)) {
            $recipients = [];
        }

        return [
            'reportMonth' => (string) $record->report_month,
            'startDate' => (string) $record->start_date,
            'endDate' => (string) $record->end_date,
            'storedPath' => (string) ($record->stored_path ?? ''),
            'pdfExists' => $this->storedPdfExists((string) ($record->stored_path ?? '')),
            'status' => (string) ($record->status ?? ''),
            'generatedAt' => $record->generated_at ? Carbon::parse($record->generated_at)->toDateTimeString() : null,
            'emailSentAt' => $record->email_sent_at ? Carbon::parse($record->email_sent_at)->toDateTimeString() : null,
            'publicTokenExpiresAt' => $record->public_token_expires_at ? Carbon::parse($record->public_token_expires_at)->toDateTimeString() : null,
            'recipientCount' => count($recipients),
            'errorMessage' => $record->error_message ? (string) $record->error_message : null,
            'payloadHash' => property_exists($record, 'payload_hash') ? (string) ($record->payload_hash ?? '') : '',
            'generationDurationMs' => property_exists($record, 'generation_duration_ms') ? ($record->generation_duration_ms !== null ? (int) $record->generation_duration_ms : null) : null,
        ];
    }

    private function defaultScheduleSettings(): array
    {
        $settings = [
            'enabled' => true,
            'interval_value' => 1,
            'interval_unit' => 'months',
            'start_date' => CarbonImmutable::now((string) config('app.timezone'))->startOfMonth()->toDateString(),
            'send_time' => '08:30',
            'last_attempt_at' => null,
            'last_sent_at' => null,
            'last_status' => null,
            'last_error' => null,
            'updated_by_staff_id' => null,
            'updated_by_code' => null,
        ];

        return [
            ...$settings,
            'next_send_at' => $this->nextScheduledSendAt($settings)?->toDateTimeString(),
        ];
    }

    private function formatScheduleSettings(object|array $row, bool $tableReady): array
    {
        $value = static function (string $key) use ($row): mixed {
            return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
        };

        $intervalValue = max(1, (int) ($value('interval_value') ?? 1));
        $intervalUnit = (string) ($value('interval_unit') ?: 'months');

        return [
            'tableReady' => $tableReady,
            'enabled' => (bool) $value('enabled'),
            'intervalValue' => $intervalValue,
            'intervalUnit' => in_array($intervalUnit, self::SCHEDULE_UNITS, true) ? $intervalUnit : 'months',
            'startDate' => (string) ($value('start_date') ?: now()->toDateString()),
            'sendTime' => (string) ($value('send_time') ?: '08:30'),
            'nextSendAt' => $value('next_send_at') ? Carbon::parse($value('next_send_at'))->toDateTimeString() : null,
            'lastAttemptAt' => $value('last_attempt_at') ? Carbon::parse($value('last_attempt_at'))->toDateTimeString() : null,
            'lastSentAt' => $value('last_sent_at') ? Carbon::parse($value('last_sent_at'))->toDateTimeString() : null,
            'lastStatus' => $value('last_status') ? (string) $value('last_status') : null,
            'lastError' => $value('last_error') ? (string) $value('last_error') : null,
            'summary' => $this->scheduleSummary($intervalValue, $intervalUnit, (string) ($value('send_time') ?: '08:30')),
        ];
    }

    private function nextScheduledSendAt(array $settings, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        if (! (bool) ($settings['enabled'] ?? true)) {
            return null;
        }

        $timezone = (string) config('app.timezone');
        $after ??= CarbonImmutable::now($timezone);
        $sendTime = (string) ($settings['send_time'] ?? $settings['sendTime'] ?? '08:30');
        [$hour, $minute] = array_map('intval', explode(':', $sendTime));
        $candidate = CarbonImmutable::parse((string) ($settings['start_date'] ?? $settings['startDate'] ?? $after->toDateString()), $timezone)
            ->setTime($hour, $minute);

        $guard = 0;
        while ($candidate->lessThanOrEqualTo($after) && $guard < 20000) {
            $candidate = $this->addScheduleInterval(
                $candidate,
                max(1, (int) ($settings['interval_value'] ?? $settings['intervalValue'] ?? 1)),
                (string) ($settings['interval_unit'] ?? $settings['intervalUnit'] ?? 'months')
            );
            $guard++;
        }

        return $candidate;
    }

    private function addScheduleInterval(CarbonImmutable $date, int $value, string $unit): CarbonImmutable
    {
        return match ($unit) {
            'days' => $date->addDays($value),
            'weeks' => $date->addWeeks($value),
            default => $date->addMonthsNoOverflow($value),
        };
    }

    private function scheduleSummary(int $value, string $unit, string $time): string
    {
        $singular = rtrim($unit, 's');
        $label = $value === 1 ? $singular : $unit;

        return "Every {$value} {$label} at {$time}";
    }

    private function reportRecord(string $month): ?object
    {
        if (! Schema::hasTable('dashboard_monthly_reports')) {
            throw new \RuntimeException('dashboard_monthly_reports table is missing.');
        }

        return DB::table('dashboard_monthly_reports')->where('report_month', $month)->first();
    }

    private function storedPdfExists(string $path): bool
    {
        return $path !== '' && Storage::disk('private')->exists($path);
    }

    private function storedPdfResponse(string $path, string $filename, string $disposition)
    {
        if (! $this->storedPdfExists($path)) {
            abort(404);
        }

        return response(Storage::disk('private')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function requireReportMonth(string $month): string
    {
        $normalized = $this->normalizeReportMonth($month);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Invalid report month. Use YYYY-MM.');
        }

        return $normalized;
    }

    private function normalizeReportMonth(string $month): ?string
    {
        $month = trim($month);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
            return $date->format('Y-m') === $month ? $date->format('Y-m') : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function monthRange(string $month): array
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

        return [
            'start' => $date->startOfYear()->toDateString(),
            'end' => $date->endOfMonth()->toDateString(),
        ];
    }

    private function periodLabel(string $startDate, string $endDate): string
    {
        return Carbon::parse($startDate)->format('d M Y').' to '.Carbon::parse($endDate)->format('d M Y');
    }

    private function monthLabel(string $month): string
    {
        try {
            return Carbon::parse($month.'-01')->format('M Y');
        } catch (Throwable) {
            return $month;
        }
    }

    private function downloadName(string $month): string
    {
        return "monthly-dashboard-management-report-{$month}.pdf";
    }

    private function sum(array $rows, string $key): float
    {
        return array_reduce($rows, fn ($sum, $row) => $sum + (float) ($row[$key] ?? 0), 0.0);
    }

    private function money(mixed $value): string
    {
        return 'RM '.number_format((float) $value, 2);
    }

    private function count(mixed $value): string
    {
        $number = (float) $value;
        if (abs($number - round($number)) < 0.00001) {
            return number_format($number, 0);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ','), '0'), '.');
    }

    private function unitCount(mixed $value, string $singular, ?string $plural = null): string
    {
        $number = (float) $value;
        $label = abs($number - 1.0) < 0.00001 ? $singular : ($plural ?? $singular.'s');

        return $this->count($number).' '.$label;
    }

    private function arialFontFaceCss(): string
    {
        $path = 'C:\\Windows\\Fonts\\arial.ttf';
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        return "@font-face { font-family: ReportArial; font-style: normal; font-weight: normal; src: url('data:font/truetype;base64,".base64_encode((string) file_get_contents($path))."') format('truetype'); }";
    }
}
