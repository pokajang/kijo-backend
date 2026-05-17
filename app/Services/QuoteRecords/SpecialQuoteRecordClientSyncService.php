<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SpecialLineItemsByServiceRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialQuoteRecordClientSyncService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function syncClientSpecial(SyncClientRequest $request): JsonResponse
    {
        return $this->syncClient($request, 'special', '%special%', 'quotes_special');
    }

    private function syncClient(SyncClientRequest $request, string $quoteType, string $projectTypePattern, string $quoteTable): JsonResponse
    {
        DB::beginTransaction();
        try {
            $quoteId = (int) $request->input('quote_id');

            $quote = DB::table($quoteTable)
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->first(['id', 'client_id', 'client_name', 'client_ssm', 'client_address',
                          'client_city', 'client_state', 'client_zip',
                          'pic_name', 'pic_email', 'pic_phone', 'pic_position']);

            if (!$quote) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
            }

            $clientId = (int) ($quote->client_id ?? 0);
            if ($clientId <= 0) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quote has no client_id.'], 422);
            }

            $projectIds = DB::select("
                SELECT id FROM projects_main
                WHERE quote_id = ?
                  AND (
                    quote_type = ?
                    OR (
                        (quote_type IS NULL OR TRIM(quote_type) = '')
                        AND LOWER(project_type) LIKE ?
                    )
                  )
            ", [$quoteId, $quoteType, $projectTypePattern]);
            $projectIds = array_values(array_filter(array_map(fn ($r) => (int) $r->id, $projectIds)));

            $client = DB::table('client_company')
                ->where('company_id', $clientId)
                ->whereNull('deleted_at')
                ->first(['company_name', 'ssm_number', 'address', 'city', 'state', 'zip', 'tax_id_no_tin']);

            if (!$client) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Client not found.'], 404);
            }

            $picName     = (string) ($quote->pic_name ?? '');
            $picEmail    = (string) ($quote->pic_email ?? '');
            $picPhone    = (string) ($quote->pic_phone ?? '');
            $picPosition = (string) ($quote->pic_position ?? '');

            $requestedPicId = (int) $request->input('pic_id', 0);
            $pic = null;

            if ($requestedPicId > 0) {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->where('pic_id', $requestedPicId)
                    ->first(['full_name', 'email', 'mobile_number', 'position']);

                if (!$pic) {
                    DB::rollBack();
                    return response()->json(['status' => 'error', 'message' => 'Selected PIC is not available for this client.'], 422);
                }
            }

            if (!$pic && $picEmail !== '') {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->where('email', $picEmail)
                    ->first(['full_name', 'email', 'mobile_number', 'position']);
            }

            if (!$pic && $picName !== '') {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->where('full_name', $picName)
                    ->first(['full_name', 'email', 'mobile_number', 'position']);
            }

            if (!$pic) {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->orderBy('pic_id')
                    ->first(['full_name', 'email', 'mobile_number', 'position']);
            }

            if ($pic) {
                $picName     = (string) ($pic->full_name ?? $picName);
                $picEmail    = (string) ($pic->email ?? $picEmail);
                $picPhone    = (string) ($pic->mobile_number ?? $picPhone);
                $picPosition = (string) ($pic->position ?? $picPosition);
            }

            DB::table($quoteTable)
                ->where('id', $quoteId)
                ->update([
                    'client_name'    => $client->company_name,
                    'client_ssm'     => $client->ssm_number,
                    'client_address' => $client->address,
                    'client_city'    => $client->city,
                    'client_state'   => $client->state,
                    'client_zip'     => $client->zip,
                    'pic_name'       => $picName,
                    'pic_email'      => $picEmail,
                    'pic_phone'      => $picPhone,
                    'pic_position'   => $picPosition,
                    'updated_at'     => now(),
                ]);

            $cascade = $request->input('cascade', []);
            $cascade = is_array($cascade) ? $cascade : [];

            $requestedDoIds             = array_values(array_filter(array_map('intval', $cascade['delivery_orders'] ?? [])));
            $requestedInvoiceIds        = array_values(array_filter(array_map('intval', $cascade['invoices'] ?? [])));
            $requestedReceiptInvoiceIds = array_values(array_filter(array_map('intval', $cascade['receipts'] ?? [])));
            $requestedJd14Ids           = array_values(array_filter(array_map('intval', $cascade['jd14'] ?? [])));
            $requestedInvoiceIds        = array_values(array_unique(array_merge($requestedInvoiceIds, $requestedReceiptInvoiceIds)));

            $updatedCounts = ['delivery_orders' => 0, 'invoices' => 0, 'receipts' => 0, 'jd14' => 0];

            if (!empty($requestedDoIds) || !empty($requestedInvoiceIds) || !empty($requestedJd14Ids)) {
                $allowedDoIds      = [];
                $allowedInvoiceIds = [];
                $allowedReceiptIds = [];
                $allowedJd14Ids    = [];

                if (!empty($projectIds)) {
                    $pph = implode(',', array_fill(0, count($projectIds), '?'));

                    $allowedDoIds = array_map('intval', DB::select(
                        "SELECT id FROM do_details WHERE project_id IN ({$pph})", $projectIds
                    ));
                    $allowedDoIds = array_map(fn ($r) => is_object($r) ? (int) $r->id : (int) $r, $allowedDoIds);

                    $allowedJd14Ids = array_map(fn ($r) => (int) $r->id, DB::select(
                        "SELECT id FROM invoices_jd14form WHERE project_id IN ({$pph})", $projectIds
                    ));

                    $invoiceRows = DB::select(
                        "SELECT id, receipt_no FROM invoices WHERE project_id IN ({$pph})", $projectIds
                    );
                    foreach ($invoiceRows as $row) {
                        $invId = (int) $row->id;
                        if ($invId) {
                            $allowedInvoiceIds[] = $invId;
                            if (!empty($row->receipt_no)) {
                                $allowedReceiptIds[] = $invId;
                            }
                        }
                    }
                }

                $allowedDoIds      = array_values(array_unique($allowedDoIds));
                $allowedInvoiceIds = array_values(array_unique($allowedInvoiceIds));
                $allowedReceiptIds = array_values(array_unique($allowedReceiptIds));
                $allowedJd14Ids    = array_values(array_unique($allowedJd14Ids));

                $finalDoIds      = array_values(array_intersect($requestedDoIds, $allowedDoIds));
                $finalInvoiceIds = array_values(array_intersect($requestedInvoiceIds, $allowedInvoiceIds));
                $finalReceiptIds = array_values(array_intersect($requestedReceiptInvoiceIds, $allowedReceiptIds));
                $finalJd14Ids    = array_values(array_intersect($requestedJd14Ids, $allowedJd14Ids));

                if (!empty($requestedDoIds) && empty($finalDoIds)) {
                    throw new \RuntimeException('Selected delivery orders are not related to this quote.');
                }
                if (!empty($requestedInvoiceIds) && empty($finalInvoiceIds)) {
                    throw new \RuntimeException('Selected invoices are not related to this quote.');
                }
                if (!empty($requestedReceiptInvoiceIds) && empty($finalReceiptIds)) {
                    throw new \RuntimeException('Selected receipts are not related to this quote.');
                }
                if (!empty($requestedJd14Ids) && empty($finalJd14Ids)) {
                    throw new \RuntimeException('Selected JD14 forms are not related to this quote.');
                }

                if (!empty($finalDoIds)) {
                    $ph     = implode(',', array_fill(0, count($finalDoIds), '?'));
                    $params = [$client->company_name, $client->address, $picName, $picPosition, $picEmail, $picPhone, ...$finalDoIds];
                    $updatedCounts['delivery_orders'] = DB::update(
                        "UPDATE do_details SET client_name=?, client_address=?, client_contact_name=?, client_contact_position=?, client_contact_email=?, client_contact_phone=? WHERE id IN ({$ph})",
                        $params
                    );
                }

                if (!empty($finalInvoiceIds)) {
                    $ph     = implode(',', array_fill(0, count($finalInvoiceIds), '?'));
                    $params = [
                        $clientId,
                        $client->company_name,
                        $client->ssm_number,
                        $client->tax_id_no_tin ?? null,
                        $client->address,
                        $client->city,
                        $client->state,
                        $client->zip,
                        $picName,
                        $picPhone,
                        $picEmail,
                        $picPosition,
                        ...$finalInvoiceIds,
                    ];
                    $updatedCounts['invoices'] = DB::update(
                        "UPDATE invoices SET client_id=?, invoice_client_name=?, invoice_client_ssm=?, invoice_client_tin=?, invoice_client_address=?, invoice_client_city=?, invoice_client_state=?, invoice_client_zip=?, invoice_pic_name=?, invoice_pic_phone=?, invoice_pic_email=?, invoice_pic_position=? WHERE id IN ({$ph})",
                        $params
                    );
                }

                if (!empty($finalReceiptIds)) {
                    $updatedCounts['receipts'] = count($finalReceiptIds);
                }

                if (!empty($finalJd14Ids)) {
                    $addressParts    = array_filter([$client->address ?? '', trim(($client->zip ?? '') . ' ' . ($client->city ?? '')), $client->state ?? '']);
                    $employerAddress = implode(', ', $addressParts);
                    $ph              = implode(',', array_fill(0, count($finalJd14Ids), '?'));
                    $params          = [$client->company_name, $employerAddress, ...$finalJd14Ids];
                    $updatedCounts['jd14'] = DB::update(
                        "UPDATE invoices_jd14form SET employer_name=?, employer_address=? WHERE id IN ({$ph})",
                        $params
                    );
                }
            }

            $logMessage = "Synced client details for {$quoteType} quote ID #{$quoteId}";
            if (!empty($cascade)) {
                $logMessage .= " (cascade: DO {$updatedCounts['delivery_orders']}, INV {$updatedCounts['invoices']}, JD14 {$updatedCounts['jd14']})";
            }
            $this->auditLog->log($request, $logMessage);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Client details synced successfully.',
                'updated' => $updatedCounts,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
