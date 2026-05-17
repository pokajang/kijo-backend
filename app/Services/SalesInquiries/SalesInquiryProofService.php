<?php

namespace App\Services\SalesInquiries;

use App\Services\AuditLogService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AppFilePaths;

class SalesInquiryProofService extends SalesInquiryBaseService
{

    public function proof(Request $request, int $id)
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row) {
            return $this->error('Inquiry proof not found.', 404);
        }

        $proof = $this->firstProofRow($id);
        if (!$proof || empty($proof->proof_path) || !AppFilePaths::storedPathExists((string) $proof->proof_path)) {
            return $this->error('Inquiry proof not found.', 404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $proof->proof_path,
            $proof->original_name ?: basename((string) $proof->proof_path),
        );
    }

    public function proofFile(Request $request, int $id, int $proofId)
    {
        if (!$this->tableReady()) {
            return $this->error('Sales inquiries table is not available.', 409);
        }

        $row = DB::table('sales_inquiries')->whereNull('deleted_at')->where('id', $id)->first();
        if (!$row || !$this->proofTableReady()) {
            return $this->error('Inquiry proof not found.', 404);
        }

        $proof = DB::table('sales_inquiry_proofs')
            ->whereNull('deleted_at')
            ->where('sales_inquiry_id', $id)
            ->where('id', $proofId)
            ->first();

        if (!$proof || empty($proof->proof_path) || !AppFilePaths::storedPathExists((string) $proof->proof_path)) {
            return $this->error('Inquiry proof not found.', 404);
        }

        return AppFilePaths::storedPathResponse(
            (string) $proof->proof_path,
            $proof->original_name ?: basename((string) $proof->proof_path),
        );
    }
}
