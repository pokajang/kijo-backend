<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Feedback\StoreFeedbackRequest;
use App\Http\Requests\Feedback\UpdateFeedbackRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use App\Services\Mail\SystemEmailBodyBuilder;
use App\Services\Mail\SystemEmailUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeedbackController extends Controller
{
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

        SendHtmlMailJob::dispatch(
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

        return response()->json(['status' => 'success', 'message' => 'Feedback submitted successfully.']);
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

    private function emailUrls(): SystemEmailUrlBuilder
    {
        return app(SystemEmailUrlBuilder::class);
    }
}
