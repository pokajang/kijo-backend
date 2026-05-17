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

class StaffActivityService extends StaffBaseService
{

    public function getAllActivities(ListActivityRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $perPage = (int) ($data['per_page'] ?? 200);
        $sortColumn = $this->mapActivitySortColumn((string) ($data['sortColumn'] ?? 'created_at'));
        $sortDirection = strtolower((string) ($data['sortDirection'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('user_activities')
            ->select([
                'id',
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS date"),
                'name_code as user_code',
                'action as details',
            ]);

        $this->applyActivityFilters($query, $data);
        $query->orderBy($sortColumn, $sortDirection);

        $paginator = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Activity logs loaded.',
            'data' => [
                'items' => $paginator->items(),
                'pagination' => $this->paginationMeta($paginator),
            ],
            'activities' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function generateUserActivityReport(GenerateUserActivityReportRequest $request)
    {
        if ($unauthorized = $this->denyUnlessStaffManager($request)) {
            return $unauthorized;
        }

        $data = $request->validated();
        $sortColumn = $this->mapActivitySortColumn((string) ($data['sortColumn'] ?? 'created_at'));
        $sortDirection = strtolower((string) ($data['sortDirection'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('user_activities')
            ->select([
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at"),
                'name_code',
                'action',
            ]);

        $this->applyActivityFilters($query, $data);
        $query->orderBy($sortColumn, $sortDirection);

        $activities = $query->limit(5000)->get();
        $reportMeta = $this->resolveActivityReportMeta($data);

        $html = $this->buildActivityPdfHtml($activities, $reportMeta);

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Failed to generate PDF report.'], 500);
        }

        $this->auditLog->log(
            $request,
            "Generated user activity report for period: {$reportMeta['period']} and user: {$reportMeta['user']}"
        );

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="user_activity_log.pdf"',
        ]);
    }
}
