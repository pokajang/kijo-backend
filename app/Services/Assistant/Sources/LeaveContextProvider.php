<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Leaves\LeaveEntitlementService;
use App\Services\Leaves\LeaveRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class LeaveContextProvider extends ModuleContextProvider
{
    private const PERSONAL_ROUTE_PATTERNS = [
        '~/my/leaves/records/(\d+)(?:/|$)~i',
        '~/staff/leaves/records/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly LeaveRequestService $leaves,
        private readonly LeaveEntitlementService $entitlements,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'leave';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return Schema::hasTable('hr_leaves_application')
            && (
                str_contains(strtolower($currentRoute), '/leaves')
                || $this->hasToken($question, [
                    'leave', 'leaves', 'cuti', 'mc', 'entitlement', 'balance',
                    'approval', 'approved', 'rejected',
                ])
            );
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $privileged = $this->hasAnyRole($request, ['HR', 'Manager', 'System Admin']);
        $wantsAllStaff = $privileged && ($this->hasToken($question, ['staff', 'all', 'team', 'approval']) || str_starts_with(strtolower($currentRoute), '/staff/leaves'));
        $rows = $this->leaveRows($request, $wantsAllStaff);
        $entitlements = $this->entitlementRows($request, $privileged && $wantsAllStaff);

        if ($rows === [] && $entitlements === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $rows,
            'id',
            'leave_label',
            ['leave_label', 'type', 'status', 'applicant_name', 'applicant_code', 'reason'],
            self::PERSONAL_ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches'], $wantsAllStaff));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->leaveSource((array) $resolved['row'], $entitlements, $wantsAllStaff));
        }

        $ranked = $this->resolver->rankedMatches($question, $rows, 'leave_label', [
            'leave_label', 'type', 'status', 'applicant_name', 'applicant_code', 'reason',
        ]);
        $matches = array_column(array_slice($ranked, 0, 8), 'row');

        return $this->resultFromSource($this->leaveListSource($matches ?: array_slice($rows, 0, 8), $entitlements, $wantsAllStaff));
    }

    private function leaveRows(Request $request, bool $allStaff): array
    {
        $payload = $this->responseData(fn () => $allStaff
            ? $this->leaves->getAllLeavesData($this->clonedRequest($request, '/assistant/hr/leaves', ['year' => now()->year]))
            : $this->leaves->getPersonalLeavesRecord($this->clonedRequest($request, '/assistant/hr/leaves/personal', ['year' => now()->year])));

        $rows = array_map(function ($row): array {
            $leave = (array) $row;
            $leave['leave_label'] = trim(implode(' ', array_filter([
                $leave['applicant_name'] ?? $leave['applicant_code'] ?? '',
                $leave['type'] ?? 'Leave',
                $leave['start_date'] ?? '',
                $leave['status'] ?? '',
            ])));

            return $leave;
        }, $payload['leaves'] ?? []);

        return $rows;
    }

    private function entitlementRows(Request $request, bool $allStaff): array
    {
        if (! Schema::hasTable('hr_leaves_allocation')) {
            return [];
        }

        $payload = $this->responseData(fn () => $allStaff
            ? $this->entitlements->getAllEntitlements($this->clonedRequest($request, '/assistant/hr/leaves/entitlements'))
            : $this->entitlements->getMyEntitlements($this->clonedRequest($request, '/assistant/hr/leaves/entitlements/mine')));

        return array_map(fn ($row): array => (array) $row, $payload['allocations'] ?? $payload['entitlements'] ?? []);
    }

    private function leaveSource(array $leave, array $entitlements, bool $allStaff): ?array
    {
        $id = (int) ($leave['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->source(
            "leave:{$id}",
            'leave',
            (string) ($leave['leave_label'] ?? "Leave #{$id}"),
            ($allStaff ? '/staff/leaves/records/' : '/my/leaves/records/').$id,
            [
                'leave' => $this->sanitizer->keep($leave, [
                    'id',
                    'staff_id',
                    'applicant_name',
                    'applicant_code',
                    'type',
                    'reason',
                    'start_date',
                    'start_time',
                    'end_date',
                    'end_time',
                    'duration_days',
                    'status',
                    'applied_at',
                    'reviewer_name',
                    'approver_name',
                    'canceller_name',
                ]),
                'entitlements' => $this->sanitizer->rows($entitlements, [
                    'id',
                    'leave_type',
                    'year',
                    'total_days',
                    'used_days',
                    'remaining',
                    'remarks',
                    'name_code',
                ], 8),
            ],
            420,
            'Leaves',
        );
    }

    private function leaveListSource(array $leaves, array $entitlements, bool $allStaff): ?array
    {
        $rows = $this->sanitizer->rows($leaves, [
            'id',
            'staff_id',
            'applicant_name',
            'applicant_code',
            'type',
            'start_date',
            'end_date',
            'duration_days',
            'status',
            'applied_at',
        ], 8);

        return $this->source(
            'leave:list:'.substr(sha1(json_encode([$rows, $entitlements])), 0, 12),
            'leave',
            $allStaff ? 'Staff leave records' : 'My leave records',
            $allStaff ? '/staff/leaves' : '/my/leaves',
            [
                'note' => $allStaff
                    ? 'Showing all-staff leave context because the user has a privileged HR/manager/admin role.'
                    : 'Showing only the current user personal leave context.',
                'leaves' => $rows,
                'entitlements' => $this->sanitizer->rows($entitlements, [
                    'id',
                    'leave_type',
                    'year',
                    'total_days',
                    'used_days',
                    'remaining',
                    'remarks',
                    'name_code',
                ], 8),
            ],
            330,
            'Leaves',
        );
    }

    private function ambiguousSource(array $matches, bool $allStaff): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'applicant_name',
            'applicant_code',
            'type',
            'start_date',
            'status',
        ], 5);

        return $this->source(
            'leave:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous leave matches',
            $allStaff ? '/staff/leaves' : '/my/leaves',
            [
                'note' => 'The question matched multiple leave records. Ask again with the exact leave ID, staff, or date.',
                'matches' => $rows,
            ],
            360,
            'Leaves',
        );
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }
}
