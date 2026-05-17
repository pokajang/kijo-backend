<?php

namespace App\Services\SalesInquiries;

use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SalesInquiryLinkService extends SalesInquiryBaseService
{

    public function linkClient(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'client_name' => ['nullable', 'string', 'max:191'],
        ]);

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        $client = DB::table('client_company')
            ->where('company_id', (int) $data['client_id'])
            ->whereNull('deleted_at')
            ->first();

        if (!$client) {
            return $this->error('Client not found.', 404);
        }

        DB::table('sales_inquiries')
            ->where('id', $id)
            ->update([
                'client_id' => (int) $client->company_id,
                'client_name' => trim((string) ($data['client_name'] ?? $client->company_name)),
                'status' => 'converted_client',
                'updated_at' => now(),
            ]);

        $row = DB::table('sales_inquiries')->where('id', $id)->first();
        return $this->success($this->mapRow($row), 'Inquiry linked to client.');
    }

    public function linkQuote(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $data = $request->validate([
            'quote_id' => ['required', 'integer', 'min:1'],
            'quote_ref_no' => ['nullable', 'string', 'max:80'],
            'service_type' => ['nullable', 'string', 'max:40'],
        ]);

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        DB::table('sales_inquiries')
            ->where('id', $id)
            ->update([
                'quote_id' => (int) $data['quote_id'],
                'quote_ref_no' => trim((string) ($data['quote_ref_no'] ?? '')) ?: null,
                'quote_service_type' => trim((string) ($data['service_type'] ?? '')) ?: null,
                'status' => 'quote_created',
                'updated_at' => now(),
            ]);

        $row = DB::table('sales_inquiries')->where('id', $id)->first();
        return $this->success($this->mapRow($row), 'Inquiry linked to quote.');
    }

    public function assignOwner(Request $request, int $id): JsonResponse
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $data = $request->validate([
            'staff_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry not found.', 404);
        }

        $staffId = (int) ($data['staff_id'] ?? 0);
        $staff = null;

        if ($staffId > 0) {
            $staff = DB::table('staff_general')
                ->select(['staff_id', 'full_name', 'name_code'])
                ->whereNull('deleted_at')
                ->where('staff_id', $staffId)
                ->first();

            if (!$staff) {
                return $this->error('Staff not found.', 404);
            }
        }

        DB::table('sales_inquiries')
            ->where('id', $id)
            ->update([
                'owner_staff_id' => $staff ? (int) $staff->staff_id : null,
                'owner_staff_code' => $staff ? (trim((string) $staff->name_code) ?: null) : null,
                'owner_staff_name' => $staff ? (trim((string) $staff->full_name) ?: null) : null,
                'owner_assigned_by_id' => (int) $request->session()->get('staff_id', 0) ?: null,
                'owner_assigned_by_code' => trim((string) $request->session()->get('name_code', '')) ?: null,
                'owner_assigned_by_name' => trim((string) $request->session()->get('full_name', '')) ?: null,
                'owner_assigned_at' => now(),
                'updated_at' => now(),
            ]);

        $this->auditLog->log(
            $request,
            $staff
                ? 'Assigned sales inquiry to ' . trim((string) $staff->full_name)
                : 'Cleared sales inquiry PIC assignment'
        );

        $updated = DB::table('sales_inquiries')->where('id', $id)->first();
        return $this->success($this->mapRow($updated), 'Inquiry PIC assignment updated.');
    }
}
