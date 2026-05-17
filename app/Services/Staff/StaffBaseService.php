<?php

namespace App\Services\Staff;

use App\Http\Requests\Staff\GenerateUserActivityReportRequest;
use App\Http\Requests\Staff\GetStaffByIdRequest;
use App\Http\Requests\Staff\ListActivityRequest;
use App\Http\Requests\Staff\ListStaffRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateProfileRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Jobs\SendHtmlMailJob;
use App\Services\AuditLogService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class StaffBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    protected function denyUnlessStaffManager(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $roles = $this->sessionRoles($request);
        $allowed = ['System Admin', 'HR'];

        foreach ($allowed as $role) {
            if (in_array($role, $roles, true)) {
                return null;
            }
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized: insufficient role for this staff management action.',
        ], 403);
    }

    protected function sessionRoles(Request $request): array
    {
        $raw = $request->session()->get('roles', []);
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map(
            fn ($r) => trim((string) $r),
            $raw
        ), fn ($r) => $r !== ''));
    }

    protected function decodeRoles(mixed $raw): array
    {
        if (is_array($raw)) {
            return $this->normalizeRoles($raw);
        }
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            return $this->normalizeRoles($decoded);
        }

        $single = trim((string) $raw);
        return $single === '' ? [] : [$single];
    }

    protected function normalizeRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            $value = trim((string) $role);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    protected function applyActivityFilters($query, array $data): void
    {
        $searchTerm = strtolower(trim((string) ($data['searchTerm'] ?? '')));
        $userFilter = strtolower(trim((string) ($data['userFilter'] ?? 'all')));
        $periodFilter = (string) ($data['periodFilter'] ?? '1y');
        $customStart = $data['customStartDate'] ?? null;
        $customEnd = $data['customEndDate'] ?? null;
        $monthFilter = $data['monthFilter'] ?? null;

        if ($searchTerm !== '') {
            $query->where(function ($sub) use ($searchTerm) {
                $sub->whereRaw('LOWER(name_code) LIKE ?', ["%{$searchTerm}%"])
                    ->orWhereRaw('LOWER(action) LIKE ?', ["%{$searchTerm}%"]);
            });
        }

        if ($userFilter !== '' && $userFilter !== 'all') {
            $query->whereRaw('LOWER(name_code) = ?', [$userFilter]);
        }

        $now = now();
        $startDate = null;
        $endDate = null;

        if ($periodFilter === '1w') {
            $startDate = $now->copy()->subDays(7)->toDateString();
        } elseif ($periodFilter === '1m') {
            $startDate = $now->copy()->subMonth()->toDateString();
        } elseif ($periodFilter === '1y') {
            $startDate = $now->copy()->subYear()->toDateString();
        } elseif ($periodFilter === 'custom') {
            $startDate = $customStart;
            $endDate = $customEnd;
        } elseif ($periodFilter === 'by_month' && is_string($monthFilter) && preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
            $startDate = "{$monthFilter}-01";
            $endDate = date('Y-m-t', strtotime($startDate));
        }

        if ($startDate) {
            $query->where('created_at', '>=', "{$startDate} 00:00:00");
        }
        if ($endDate) {
            $query->where('created_at', '<=', "{$endDate} 23:59:59");
        }
    }

    protected function mapActivitySortColumn(string $input): string
    {
        return match ($input) {
            'name_code', 'user_code' => 'name_code',
            'action', 'details' => 'action',
            default => 'created_at',
        };
    }

    protected function resolveActivityReportMeta(array $data): array
    {
        $periodFilter = (string) ($data['periodFilter'] ?? '1y');
        $customStart = (string) ($data['customStartDate'] ?? '');
        $customEnd = (string) ($data['customEndDate'] ?? '');
        $monthFilter = (string) ($data['monthFilter'] ?? '');
        $userFilter = strtolower(trim((string) ($data['userFilter'] ?? 'all')));

        $period = 'All Time';
        if ($periodFilter === 'custom' && $customStart !== '' && $customEnd !== '') {
            $period = date('d M Y', strtotime($customStart)) . ' to ' . date('d M Y', strtotime($customEnd));
        } elseif ($periodFilter === '1w') {
            $period = 'Last 1 Week';
        } elseif ($periodFilter === '1m') {
            $period = 'Last 1 Month';
        } elseif ($periodFilter === '1y') {
            $period = 'Last 1 Year';
        } elseif ($periodFilter === 'by_month' && $monthFilter !== '') {
            $period = date('F Y', strtotime($monthFilter . '-01'));
        }

        return [
            'date' => now()->format('d M Y'),
            'period' => $period,
            'user' => $userFilter === 'all' ? 'All Users' : strtoupper($userFilter),
        ];
    }

    protected function buildActivityPdfHtml($activities, array $meta): string
    {
        $rows = '';
        foreach ($activities as $index => $row) {
            $rows .= '<tr>'
                . '<td>' . ($index + 1) . '</td>'
                . '<td>' . htmlspecialchars((string) $row->created_at, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($row->name_code ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($row->action ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }
    h1 { font-size: 16px; margin: 0 0 10px; }
    .meta { margin-bottom: 12px; }
    .meta div { margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #cccccc; padding: 6px; vertical-align: top; }
    th { background: #f2f2f2; text-align: left; }
  </style>
</head>
<body>
  <h1>KIJO USER ACTIVITY LOGS</h1>
  <div class="meta">
    <div><strong>Report Date:</strong> ' . htmlspecialchars($meta['date'], ENT_QUOTES, 'UTF-8') . '</div>
    <div><strong>Report Period:</strong> ' . htmlspecialchars($meta['period'], ENT_QUOTES, 'UTF-8') . '</div>
    <div><strong>User:</strong> ' . htmlspecialchars($meta['user'], ENT_QUOTES, 'UTF-8') . '</div>
  </div>
  <table>
    <thead>
      <tr>
        <th style="width: 6%;">#</th>
        <th style="width: 25%;">Date-Time</th>
        <th style="width: 12%;">User</th>
        <th style="width: 57%;">Activity</th>
      </tr>
    </thead>
    <tbody>' . $rows . '</tbody>
  </table>
</body>
</html>';
    }
}
