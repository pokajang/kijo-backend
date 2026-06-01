<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Stats\MonthlyDashboardReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AdminMonthlyDashboardReportTestController extends Controller
{
    public function __construct(private readonly MonthlyDashboardReportService $reports) {}

    public function status(): JsonResponse
    {
        $data = $this->reports->statusSummary();
        $data['logs'] = $this->recentTestLogs();
        $data['schedule'] = $this->reports->scheduleSettings();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function updateSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'intervalValue' => ['required', 'integer', 'min:1', 'max:365'],
            'intervalUnit' => ['required', 'string', 'in:days,weeks,months'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'sendTime' => ['required', 'date_format:H:i'],
        ]);

        try {
            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard report email schedule saved.',
                'data' => [
                    'schedule' => $this->reports->updateScheduleSettings($data, $request),
                ],
            ]);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'schedule' => [$exception->getMessage()],
            ]);
        }
    }

    public function trigger(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'recipients' => ['required', 'string', 'max:4000'],
            'force' => ['nullable', 'boolean'],
        ]);

        $month = trim((string) ($data['month'] ?? '')) ?: $this->reports->previousReportMonth();
        $recipients = [];

        try {
            $recipients = $this->reports->parseRecipientList((string) $data['recipients'], true);
            if (empty($recipients)) {
                throw ValidationException::withMessages([
                    'recipients' => ['At least one test recipient is required.'],
                ]);
            }

            $result = $this->reports->sendTestForMonth(
                $month,
                $recipients,
                $request->boolean('force'),
                $request
            );
            $log = $this->insertTestLog(
                $request,
                $month,
                $recipients,
                'sent',
                'Year-to-date dashboard report test email sent.',
                (string) ($result['url'] ?? ''),
                (string) ($result['publicTokenExpiresAt'] ?? '')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Year-to-date dashboard report test email sent.',
                'data' => [
                    ...$result,
                    'log' => $log,
                    'reportMonth' => $month,
                    'recipientCount' => count($recipients),
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            $field = str_contains(strtolower($exception->getMessage()), 'month') ? 'month' : 'recipients';
            throw ValidationException::withMessages([
                $field => [$exception->getMessage()],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Year-to-date dashboard report test trigger failed', ['exception' => $exception]);
            $log = $recipients !== []
                ? $this->insertTestLog(
                    $request,
                    $month,
                    $recipients,
                    'failed',
                    $exception->getMessage() ?: 'Year-to-date dashboard report test trigger failed.',
                    '',
                    ''
                )
                : null;

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage() ?: 'Year-to-date dashboard report test trigger failed.',
                'data' => [
                    'log' => $log,
                    'reportMonth' => $month,
                ],
            ], 500);
        }
    }

    private function recentTestLogs(): array
    {
        if (! Schema::hasTable('dashboard_monthly_report_test_logs')) {
            return [];
        }

        return DB::table('dashboard_monthly_report_test_logs')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => $this->formatTestLog($row))
            ->all();
    }

    private function insertTestLog(
        Request $request,
        string $month,
        array $recipients,
        string $status,
        string $message,
        string $url,
        string $expiresAt
    ): ?array {
        if (! Schema::hasTable('dashboard_monthly_report_test_logs')) {
            return null;
        }

        $recipientEmail = implode(', ', array_map(
            fn (array $recipient): string => (string) ($recipient['email'] ?? ''),
            $recipients
        ));
        $now = now();

        $id = DB::table('dashboard_monthly_report_test_logs')->insertGetId([
            'report_month' => $month,
            'recipient_email' => $recipientEmail,
            'status' => $status,
            'response_message' => $message,
            'public_url' => $url !== '' ? $url : null,
            'public_token_expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'staff_id' => $request->session()->get('staff_id') ?: null,
            'name_code' => substr((string) $request->session()->get('name_code', ''), 0, 20) ?: null,
            'ip_address' => substr((string) $request->ip(), 0, 45) ?: null,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::table('dashboard_monthly_report_test_logs')->where('id', $id)->first();

        return $row ? $this->formatTestLog($row) : null;
    }

    private function formatTestLog(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'reportMonth' => (string) $row->report_month,
            'recipient' => (string) $row->recipient_email,
            'status' => (string) $row->status,
            'response' => (string) ($row->response_message ?? ''),
            'url' => (string) ($row->public_url ?? ''),
            'publicTokenExpiresAt' => $row->public_token_expires_at,
            'completedAt' => $row->completed_at,
        ];
    }
}
