<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Requests\Feedback\UpdateFeedbackRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FeedbackController extends Controller
{
    private const COMPLETED_STATUS = 'Fixed Completed';
    private const SLA_DAYS = 30;

    public function __construct(private AuditLogService $auditLog) {}

    public function index(Request $request)
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $year = (int) $request->query('year', 0);

        $query = DB::table('system_feedbacks as f')
            ->leftJoin('staff_general as s', 'f.reported_by', '=', 's.staff_id')
            ->select([
                'f.id',
                'f.feedback',
                DB::raw('DATE(f.date_reported) as date_reported'),
                'f.status',
                DB::raw('DATE(f.action_date) as action_date'),
                Schema::hasColumn('system_feedbacks', 'fixed_at')
                    ? DB::raw('DATE(f.fixed_at) as fixed_at')
                    : DB::raw('NULL as fixed_at'),
                'f.remarks',
                DB::raw('f.reported_by as reported_by_id'),
                DB::raw("COALESCE(s.name_code, CONCAT('Staff #', f.reported_by)) as reported_by"),
            ]);

        if ($year >= 2000 && $year <= 2100) {
            $query->whereYear('f.date_reported', $year);
        }

        $paginator = $query
            ->orderByRaw("CASE WHEN f.status = 'Pending' THEN 0 ELSE 1 END")
            ->orderBy('f.date_reported', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status'     => 'success',
            'feedbacks'  => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function monthlyMetrics(Request $request)
    {
        $currentYear = (int) now()->year;
        $year = (int) $request->query('year', $currentYear);

        if ($year < 2000 || $year > 2100) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid year.',
            ], 422);
        }

        $hasFixedAt = Schema::hasColumn('system_feedbacks', 'fixed_at');
        $rows = DB::table('system_feedbacks')
            ->select([
                'id',
                'status',
                DB::raw('DATE(date_reported) as date_reported'),
                $hasFixedAt ? DB::raw('DATE(fixed_at) as fixed_at') : DB::raw('NULL as fixed_at'),
            ])
            ->whereYear('date_reported', $year)
            ->get();

        $today = CarbonImmutable::today();
        $lastMonth = $year === $currentYear ? (int) now()->month : 12;
        $monthly = [];

        for ($month = 1; $month <= $lastMonth; $month++) {
            $monthKey = sprintf('%04d-%02d', $year, $month);
            $monthly[$monthKey] = [
                'month' => $monthKey,
                'month_label' => CarbonImmutable::create($year, $month, 1)->format('M Y'),
                'reported_count' => 0,
                'eligible_count' => 0,
                'completed_count' => 0,
                'fixed_under_30_count' => 0,
                'missed_30_count' => 0,
                'open_within_window_count' => 0,
                'sla_percent' => null,
                'is_final' => true,
            ];
        }

        foreach ($rows as $row) {
            if (! $row->date_reported) {
                continue;
            }

            $reportedAt = CarbonImmutable::parse($row->date_reported)->startOfDay();
            $monthKey = $reportedAt->format('Y-m');

            if (! isset($monthly[$monthKey])) {
                continue;
            }

            $deadline = $reportedAt->addDays(self::SLA_DAYS);
            $fixedAt = $row->fixed_at ? CarbonImmutable::parse($row->fixed_at)->startOfDay() : null;
            $isCompleted = $row->status === self::COMPLETED_STATUS && $fixedAt !== null;
            $isWithinWindow = $isCompleted && $fixedAt->lte($deadline);
            $isMaturedOpen = ! $isCompleted && $today->gt($deadline);

            $monthly[$monthKey]['reported_count']++;

            if ($isCompleted) {
                $monthly[$monthKey]['completed_count']++;
                $monthly[$monthKey]['eligible_count']++;
            } elseif ($isMaturedOpen) {
                $monthly[$monthKey]['eligible_count']++;
            } else {
                $monthly[$monthKey]['open_within_window_count']++;
                $monthly[$monthKey]['is_final'] = false;
            }

            if ($isWithinWindow) {
                $monthly[$monthKey]['fixed_under_30_count']++;
            }
        }

        foreach ($monthly as &$month) {
            $month['missed_30_count'] = max(
                0,
                $month['eligible_count'] - $month['fixed_under_30_count'],
            );
            $month['sla_percent'] = $month['eligible_count'] > 0
                ? round(($month['fixed_under_30_count'] / $month['eligible_count']) * 100, 1)
                : null;
        }
        unset($month);

        return response()->json([
            'status' => 'success',
            'year' => $year,
            'target_percent' => 90,
            'sla_days' => self::SLA_DAYS,
            'completed_status' => self::COMPLETED_STATUS,
            'months' => array_values($monthly),
        ]);
    }

    public function store(StoreFeedbackRequest $request)
    {
        $feedback  = $request->validated()['feedback'];
        $staffId   = $request->session()->get('staff_id');
        $nameCode  = $request->session()->get('name_code', 'XXX');

        $feedbackId = DB::table('system_feedbacks')->insertGetId([
            'feedback'    => $feedback,
            'reported_by' => $staffId,
        ]);

        $this->auditLog->log($request, "Submitted feedback ticket #{$feedbackId}");

        $mailSent = true;
        $mailMessage = 'Feedback submitted successfully.';

        try {
            SendHtmlMailJob::dispatchSync(
                'azam@amiosh.com',
                'System Admin',
                'New System Ticket Submitted',
                $this->emailBody()->render([
                    'intro' => 'A new system ticket has been submitted in KIJO.',
                    'status' => ['label' => 'New Ticket', 'tone' => 'warning'],
                    'detailsHeading' => 'Ticket Details',
                    'details' => [
                        'Submitted By' => "{$nameCode} (ID: {$staffId})",
                        'Feedback' => $feedback,
                    ],
                    'actionUrl' => $this->emailUrls()->frontendUrl("/support/feedback/{$feedbackId}"),
                    'actionLabel' => 'Open system ticket',
                    'signOff' => false,
                ]),
                [],
                null,
                null,
                $this->emailBody()->presentation('System Ticket', 'New System Ticket Submitted', 'Action required', 'A new system ticket has been submitted in KIJO.'),
            );
        } catch (Throwable $e) {
            report($e);
            $mailSent = false;
            $mailMessage = 'Feedback submitted successfully, but the email notification could not be sent.';
        }

        return response()->json([
            'status' => 'success',
            'message' => $mailMessage,
            'mail_sent' => $mailSent,
        ]);
    }

    public function update(UpdateFeedbackRequest $request, int $id)
    {
        $row = DB::table('system_feedbacks')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Feedback not found'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $roles   = (array) $request->session()->get('roles', []);
        $isAdmin = in_array('System Admin', $roles, true);
        $isOwner = $staffId > 0 && $staffId === (int) $row->reported_by;

        if (!$isAdmin && !$isOwner) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized: only owner or System Admin can edit this feedback',
            ], 403);
        }

        $validated = $request->validated();

        if (!$isAdmin) {
            // Owner: only allowed to edit the feedback text
            if (!isset($validated['feedback'])) {
                return response()->json(['status' => 'error', 'message' => 'Feedback text is required'], 400);
            }
            DB::table('system_feedbacks')->where('id', $id)->update(['feedback' => $validated['feedback']]);
            $this->auditLog->log($request, "Updated feedback ticket #{$id}");
            return response()->json(['status' => 'success', 'message' => 'Feedback updated']);
        }

        // Admin: can update any of the validated fields
        $updates = array_filter([
            'status'      => $validated['status'] ?? null,
            'action_date' => array_key_exists('action_date', $validated) ? ($validated['action_date'] ?: null) : null,
            'remarks'     => $validated['remarks'] ?? null,
            'feedback'    => $validated['feedback'] ?? null,
        ], fn ($v, $k) => array_key_exists($k, $validated), ARRAY_FILTER_USE_BOTH);

        if (empty($updates)) {
            return response()->json(['status' => 'error', 'message' => 'No fields to update'], 400);
        }

        if (
            Schema::hasColumn('system_feedbacks', 'fixed_at')
            && (array_key_exists('status', $validated) || array_key_exists('action_date', $validated))
        ) {
            $effectiveStatus = $validated['status'] ?? $row->status;

            if ($effectiveStatus === self::COMPLETED_STATUS) {
                $updates['fixed_at'] = array_key_exists('action_date', $validated) && ! empty($validated['action_date'])
                    ? $this->resolveFixedAtFromUpdate($validated)
                    : ($row->fixed_at ?: $this->resolveFixedAtFromUpdate($validated));
            } elseif (array_key_exists('status', $validated)) {
                $updates['fixed_at'] = null;
            }
        }

        DB::table('system_feedbacks')->where('id', $id)->update($updates);
        $this->auditLog->log($request, "Updated feedback ticket #{$id}");
        return response()->json(['status' => 'success', 'message' => 'Feedback updated']);
    }

    public function destroy(Request $request, int $id)
    {
        $row = DB::table('system_feedbacks')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Feedback not found'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $roles   = (array) $request->session()->get('roles', []);
        $isAdmin = in_array('System Admin', $roles, true);
        $isOwner = $staffId > 0 && $staffId === (int) $row->reported_by;

        if (!$isAdmin && !$isOwner) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthorized: only owner or System Admin can delete this feedback',
            ], 403);
        }

        DB::table('system_feedbacks')->where('id', $id)->delete();
        $this->auditLog->log($request, "Deleted feedback ticket #{$id}");
        return response()->json(['status' => 'success', 'message' => 'Feedback deleted']);
    }

    private function emailBody(): SystemEmailBodyBuilder
    {
        return app(SystemEmailBodyBuilder::class);
    }

    private function resolveFixedAtFromUpdate(array $validated): CarbonImmutable
    {
        if (! empty($validated['action_date'])) {
            return CarbonImmutable::parse($validated['action_date'])->startOfDay();
        }

        return CarbonImmutable::now();
    }

    private function emailUrls(): SystemEmailUrlBuilder
    {
        return app(SystemEmailUrlBuilder::class);
    }
}
