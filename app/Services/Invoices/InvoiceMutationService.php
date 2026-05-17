<?php

namespace App\Services\Invoices;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceMutationService extends InvoiceBaseService
{

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'project_id'   => 'required|integer|min:1',
            'service_type' => 'required|string',
            'breakdown'    => 'required|array|min:1',
        ]);

        $staffId     = (int) $request->session()->get('staff_id', 0);
        $creatorCode = (string) $request->session()->get('name_code', '');

        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $projectId      = (int) $request->input('project_id');
        $serviceType    = trim((string) $request->input('service_type'));
        $quoteIdRaw     = $request->input('quote_id');
        $quoteId        = ($quoteIdRaw !== null && $quoteIdRaw !== '') ? (int) $quoteIdRaw : null;
        $invoicePurpose = trim((string) $request->input('invoice_purpose', ''));
        $grantNo        = trim((string) $request->input('grant_approval_no', ''));

        // Duplicate grant_no check
        if ($grantNo !== '') {
            $existing = DB::table('invoices')->where('grant_approval_no', $grantNo)->first(['id']);
            if ($existing) {
                return response()->json([
                    'status'     => 'exists',
                    'invoice_id' => $existing->id,
                    'message'    => 'This HRD Grant Approval No. is already used.',
                ]);
            }
        }

        // Duplicate invoice check (NULL-safe for quote_id)
        if (strtolower($serviceType) !== 'manpower supply') {
            $existing = DB::selectOne(
                "SELECT id, grant_approval_no FROM invoices WHERE project_id = ? AND service_type = ? AND quote_id <=> ? LIMIT 1",
                [$projectId, $serviceType, $quoteId]
            );
        } else {
            $existing = DB::table('invoices')
                ->where('project_id', $projectId)
                ->where('service_type', $serviceType)
                ->where('invoice_purpose', $invoicePurpose)
                ->first(['id', 'grant_approval_no']);
        }

        if ($existing) {
            $existingGrant = trim((string) ($existing->grant_approval_no ?? ''));
            if ($grantNo !== '' && $existingGrant === '') {
                return response()->json([
                    'status'     => 'exists',
                    'invoice_id' => $existing->id,
                    'message'    => 'Invoice exists; cannot add HRD grant retrospectively.',
                ]);
            }
            return response()->json([
                'status'     => 'exists',
                'invoice_id' => $existing->id,
                'message'    => 'An invoice for this project & service already exists.',
            ]);
        }

        $totalError = $this->invoiceTotalValidationMessage(
            $serviceType,
            (array) $request->input('breakdown', []),
            (float) $request->input('amount', 0),
            (float) $request->input('sst_amount', 0),
            (float) $request->input('grand_total', 0)
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

        $yearFull = date('Y');
        $yearTwo  = date('y');
        $lockName = "invoices_{$yearFull}";

        try {
            DB::statement("SELECT GET_LOCK(?, 10)", [$lockName]);
            DB::beginTransaction();

            $projectRow = DB::table('projects_main')->where('id', $projectId)->first(['client_id', 'proposal_language']);
            $clientId = $projectRow->client_id ?? null;
            $documentLanguage = $this->normalizeDocumentLanguage($projectRow->proposal_language ?? 'en');

            $maxRun    = (int) DB::table('invoices')->whereYear('created_at', $yearFull)->max('invoice_running_no');
            $runningNo = $maxRun + 1;
            $padded    = str_pad((string) $runningNo, 4, '0', STR_PAD_LEFT);
            $refNo     = "INV{$yearTwo}-{$padded}{$creatorCode}";

            $invoiceId = DB::table('invoices')->insertGetId([
                'project_id'             => $projectId,
                'client_id'              => $clientId,
                'invoice_loa_no'         => $request->input('client_award_ref_no'),
                'invoice_client_name'    => $request->input('invoice_client_name'),
                'invoice_client_ssm'     => $request->input('invoice_client_ssm'),
                'invoice_client_tin'     => $request->input('invoice_client_tin'),
                'invoice_client_address' => $request->input('invoice_client_address'),
                'invoice_client_city'    => $request->input('invoice_client_city'),
                'invoice_client_state'   => $request->input('invoice_client_state'),
                'invoice_client_zip'     => $request->input('invoice_client_zip'),
                'invoice_pic_name'       => $request->input('invoice_pic_name'),
                'invoice_pic_phone'      => $request->input('invoice_pic_phone'),
                'invoice_pic_email'      => $request->input('invoice_pic_email'),
                'invoice_pic_position'   => $request->input('invoice_pic_position'),
                'service_type'           => $serviceType,
                'quote_id'               => $quoteId,
                'created_by'             => $staffId,
                'invoice_ref_no'         => $refNo,
                'invoice_running_no'     => $runningNo,
                'invoice_purpose'        => $invoicePurpose,
                'invoice_date'           => $request->input('invoice_date', date('Y-m-d')),
                'amount'                 => $request->input('amount', 0),
                'sst_amount'             => $request->input('sst_amount', 0),
                'grand_total'            => $request->input('grand_total', 0),
                'payment_method'         => $request->input('payment_method', ''),
                'grant_approval_no'      => $grantNo,
                'remarks'                => $request->input('remarks', ''),
                'document_language'      => $documentLanguage,
                'status'                 => 'Pending',
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            foreach ((array) $request->input('breakdown') as $i => $line) {
                $qty    = (float) ($line['quantity'] ?? 1);
                $uprice = (float) ($line['unit_price'] ?? 0);
                DB::table('invoice_breakdown')->insert([
                    'invoice_id'       => $invoiceId,
                    'item_description' => $line['item_description'] ?? '',
                    'description'      => $line['description'] ?? null,
                    'unit'             => $line['unit'] ?? 'Lot',
                    'quantity'         => $qty,
                    'unit_price'       => $uprice,
                    'subtotal'         => $qty * $uprice,
                    'sort_order'       => $i + 1,
                ]);
            }

            $this->insertProjectProgress($projectId, "Invoice {$refNo} created.", $request);
            $this->auditLog->log($request, "Created invoice {$refNo} (service: {$serviceType}) for project {$projectId}");

            DB::commit();
            DB::statement("DO RELEASE_LOCK(?)", [$lockName]);

            return response()->json([
                'status'         => 'success',
                'invoice_id'     => $invoiceId,
                'invoice_ref_no' => $refNo,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            DB::statement("DO RELEASE_LOCK(?)", [$lockName]);
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $invoiceRef = trim((string) $request->input('invoice_ref_no', ''));
        $dateIssued = $request->input('invoice_date');
        $status     = trim((string) $request->input('status', ''));

        if ($invoiceRef === '' || !$dateIssued || $status === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields.'], 422);
        }

        $existingInvoice = DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->first(['id', 'service_type']);
        if (!$existingInvoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found.'], 404);
        }

        $totalError = $this->invoiceTotalValidationMessage(
            (string) $existingInvoice->service_type,
            (array) $request->input('breakdown', []),
            (float) $request->input('amount', 0),
            (float) $request->input('sst_amount', 0),
            (float) $request->input('grand_total', 0)
        );
        if ($totalError !== null) {
            return response()->json(['status' => 'error', 'message' => $totalError], 422);
        }

        try {
            DB::table('invoices')->where('invoice_ref_no', $invoiceRef)->limit(1)->update([
                'invoice_loa_no'         => $request->input('invoice_loa_no'),
                'invoice_client_name'    => $request->input('invoice_client_name'),
                'invoice_client_ssm'     => $request->input('invoice_client_ssm'),
                'invoice_client_tin'     => $request->input('invoice_client_tin'),
                'invoice_client_address' => $request->input('invoice_client_address'),
                'invoice_client_city'    => $request->input('invoice_client_city'),
                'invoice_client_state'   => $request->input('invoice_client_state'),
                'invoice_client_zip'     => $request->input('invoice_client_zip'),
                'invoice_pic_name'       => $request->input('invoice_pic_name'),
                'invoice_pic_phone'      => $request->input('invoice_pic_phone'),
                'invoice_pic_email'      => $request->input('invoice_pic_email'),
                'invoice_pic_position'   => $request->input('invoice_pic_position'),
                'invoice_purpose'        => $request->input('invoice_purpose', ''),
                'invoice_date'           => $dateIssued,
                'status'                 => $status,
                'amount'                 => $request->input('amount', 0),
                'sst_amount'             => $request->input('sst_amount', 0),
                'grand_total'            => $request->input('grand_total', 0),
                'payment_method'         => $request->input('payment_method', ''),
                'grant_approval_no'      => $request->input('grant_approval_no'),
                'paid_date'              => $request->input('paid_date'),
                'paid_amount'            => $request->input('paid_amount'),
                'paid_remarks'           => $request->input('paid_remarks', ''),
                'remarks'                => $request->input('remarks', ''),
                'updated_at'             => now(),
            ]);

            $invId = $existingInvoice->id;
            if ($invId) {
                DB::table('invoice_breakdown')->where('invoice_id', $invId)->delete();

                foreach ((array) $request->input('breakdown', []) as $i => $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $qty   = (float) ($line['quantity'] ?? 0);
                    $price = (float) ($line['unit_price'] ?? 0);
                    DB::table('invoice_breakdown')->insert([
                        'invoice_id'       => $invId,
                        'item_description' => $line['item_description'] ?? '',
                        'description'      => $line['description'] ?? null,
                        'unit'             => $line['unit'] ?? 'Lot',
                        'quantity'         => $qty,
                        'unit_price'       => $price,
                        'subtotal'         => round($qty * $price, 2),
                        'sort_order'       => $i + 1,
                    ]);
                }
            }

            $this->auditLog->log($request, "Updated invoice {$invoiceRef}");
            return response()->json(['status' => 'success', 'message' => 'Invoice updated successfully.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $invoiceRef = trim((string) $request->input('invoice_ref_no', ''));
        if ($invoiceRef === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing invoice_ref_no'], 422);
        }

        $invoice = DB::table('invoices')
            ->where('invoice_ref_no', $invoiceRef)
            ->first(['id', 'status', 'project_id']);

        if (!$invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        if (strtolower($invoice->status) !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Only invoices with status "Pending" can be deleted.'], 422);
        }

        try {
            DB::beginTransaction();
            DB::table('invoice_breakdown')->where('invoice_id', $invoice->id)->delete();
            DB::table('invoice_payment_reminder_logs')->where('invoice_id', $invoice->id)->delete();
            DB::table('invoices')->where('id', $invoice->id)->delete();

            if ($invoice->project_id) {
                $this->insertProjectProgress(
                    (int) $invoice->project_id,
                    "Invoice with reference no. {$invoiceRef} was deleted.",
                    $request
                );
            }

            $this->auditLog->log($request, "Deleted invoice {$invoiceRef}");
            DB::commit();

            return response()->json(['status' => 'success', 'message' => 'Invoice deleted successfully.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error'], 500);
        }
    }
}
