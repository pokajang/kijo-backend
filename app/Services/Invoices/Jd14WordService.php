<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use App\Services\Word\Jd14WordDocumentBuilder;
use App\Services\Word\WordRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Jd14WordService extends WordRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private Jd14WordDocumentBuilder $builder,
    ) {}

    public function downloadJd14(Request $request, int $id = 0)
    {
        if ($id <= 0) {
            $id = (int) $request->query('id', 0);
        }
        if ($id < 1) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or missing ID'], 422);
        }

        $row = DB::table('invoices_jd14form')->where('id', $id)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Record not found'], 404);
        }

        try {
            $this->auditLog->log($request, "Generated JD14 Word document for approval number {$row->approval_no}");

            return $this->download($this->builder->build($row), 'JD14-'.(string) ($row->approval_no ?? $id).'.docx');
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 500);
        }
    }
}
