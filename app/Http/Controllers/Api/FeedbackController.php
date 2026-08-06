<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Requests\Feedback\UpdateFeedbackRequest;
use App\Services\AuditLogService;
use App\Services\Feedback\FeedbackWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FeedbackController extends Controller
{
    private const COMPLETED_STATUS = 'Fixed Completed';

    private const SLA_DAYS = 30;

    private const TRACK_NEEDS_TRIAGE = 'Needs Triage';

    private const TRACK_30_DAY_FIX = '30-Day Fix';

    private const TRACK_NEXT_UPGRADE = 'Next Upgrade';

    private const TRACK_ROADMAP = 'Roadmap / Backlog';

    private const TRACK_NOT_ACTIONABLE = 'Not Actionable';

    private const TRACK_REJECTED = 'Rejected';

    private const RESOLUTION_TRACKS = [
        self::TRACK_NEEDS_TRIAGE,
        self::TRACK_30_DAY_FIX,
        self::TRACK_NEXT_UPGRADE,
        self::TRACK_ROADMAP,
        self::TRACK_NOT_ACTIONABLE,
        self::TRACK_REJECTED,
    ];

    public function __construct(
        private AuditLogService $auditLog,
        private FeedbackWorkflowService $workflow,
    ) {}

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
                Schema::hasColumn('system_feedbacks', 'resolution_track')
                    ? 'f.resolution_track'
                    : DB::raw("'".self::TRACK_NEEDS_TRIAGE."' as resolution_track"),
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

        $this->workflow->decoratePaginator($paginator);

        return response()->json([
            'status' => 'success',
            'feedbacks' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
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
        $hasResolutionTrack = Schema::hasColumn('system_feedbacks', 'resolution_track');
        $rows = DB::table('system_feedbacks')
            ->select([
                'id',
                'status',
                $hasResolutionTrack
                    ? 'resolution_track'
                    : DB::raw("'".self::TRACK_NEEDS_TRIAGE."' as resolution_track"),
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
                'sla_track_count' => 0,
                'eligible_count' => 0,
                'completed_count' => 0,
                'fixed_under_30_count' => 0,
                'missed_30_count' => 0,
                'open_within_window_count' => 0,
                'needs_triage_count' => 0,
                'next_upgrade_count' => 0,
                'roadmap_count' => 0,
                'not_actionable_count' => 0,
                'rejected_count' => 0,
                'excluded_count' => 0,
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

            $track = $this->normalizeResolutionTrack($row->resolution_track ?? null);

            $monthly[$monthKey]['reported_count']++;
            $this->incrementResolutionTrackCount($monthly[$monthKey], $track);

            if ($track !== self::TRACK_30_DAY_FIX) {
                continue;
            }

            $deadline = $reportedAt->addDays(self::SLA_DAYS);
            $fixedAt = $row->fixed_at ? CarbonImmutable::parse($row->fixed_at)->startOfDay() : null;
            $isCompleted = in_array($row->status, [self::COMPLETED_STATUS, 'Resolved'], true)
                && $fixedAt !== null;
            $isWithinWindow = $isCompleted && $fixedAt->lte($deadline);
            $isMaturedOpen = ! $isCompleted && $today->gt($deadline);

            $monthly[$monthKey]['sla_track_count']++;

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
            $month['excluded_count'] = max(0, $month['reported_count'] - $month['sla_track_count']);
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
        $feedback = $request->validated()['feedback'];
        $staffId = $request->session()->get('staff_id');

        $feedbackId = DB::transaction(function () use ($feedback, $staffId, $request): int {
            $feedbackId = (int) DB::table('system_feedbacks')->insertGetId([
                'feedback' => $feedback,
                'reported_by' => $staffId,
                ...($this->hasResolutionTrackColumn() ? ['resolution_track' => self::TRACK_NEEDS_TRIAGE] : []),
            ]);
            $this->workflow->recordReceived($request, $feedbackId);

            return $feedbackId;
        });

        $this->auditLog->log($request, "Submitted feedback ticket #{$feedbackId}");

        return response()->json([
            'status' => 'success',
            'message' => 'Feedback submitted successfully.',
            'feedback_id' => $feedbackId,
        ]);
    }

    public function update(UpdateFeedbackRequest $request, int $id)
    {
        $result = $this->workflow->update($request, $id, $request->validated());
        $this->auditLog->log($request, "Updated feedback ticket #{$id}");

        return response()->json([
            'status' => 'success',
            'message' => $result['event_id'] ? 'Feedback updated.' : 'No fields changed.',
            'feedback' => $result['feedback'],
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        if (Schema::hasTable('system_feedback_history')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Feedback tickets cannot be deleted because their workflow history is immutable.',
            ], 409);
        }

        $row = DB::table('system_feedbacks')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Feedback not found'], 404);
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        $roles = (array) $request->session()->get('roles', []);
        $isAdmin = in_array('System Admin', $roles, true);
        $isOwner = $staffId > 0 && $staffId === (int) $row->reported_by;

        if (! $isAdmin && ! $isOwner) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized: only owner or System Admin can delete this feedback',
            ], 403);
        }

        DB::table('system_feedbacks')->where('id', $id)->delete();
        $this->auditLog->log($request, "Deleted feedback ticket #{$id}");

        return response()->json(['status' => 'success', 'message' => 'Feedback deleted']);
    }

    private function normalizeResolutionTrack(?string $track): string
    {
        $normalized = trim((string) $track);

        foreach (self::RESOLUTION_TRACKS as $allowedTrack) {
            if (strcasecmp($normalized, $allowedTrack) === 0) {
                return $allowedTrack;
            }
        }

        return self::TRACK_NEEDS_TRIAGE;
    }

    private function incrementResolutionTrackCount(array &$month, string $track): void
    {
        match ($track) {
            self::TRACK_30_DAY_FIX => null,
            self::TRACK_NEXT_UPGRADE => $month['next_upgrade_count']++,
            self::TRACK_ROADMAP => $month['roadmap_count']++,
            self::TRACK_NOT_ACTIONABLE => $month['not_actionable_count']++,
            self::TRACK_REJECTED => $month['rejected_count']++,
            default => $month['needs_triage_count']++,
        };
    }

    private function hasResolutionTrackColumn(): bool
    {
        return Schema::hasColumn('system_feedbacks', 'resolution_track');
    }
}
