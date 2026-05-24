<?php

namespace App\Services\QuoteRecords;

use App\Http\Requests\QuoteRecord\AddFollowUpRequest;
use App\Http\Requests\QuoteRecord\AwardQuoteRequest;
use App\Http\Requests\QuoteRecord\FailQuoteRequest;
use App\Http\Requests\QuoteRecord\SyncClientRequest;
use App\Http\Requests\QuoteRecord\UnAwardQuoteRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManpowerQuoteRecordClientSyncService
{

    public function __construct(private AuditLogService $auditLog) {}

    public function syncClientManpower(SyncClientRequest $request): JsonResponse
    {
        return $this->syncClientForQuote($request, 'manpower', 'quotes_manpower', '%manpower%');
    }

    private function syncClientForQuote(
        SyncClientRequest $request,
        string $quoteType,
        string $quoteTable,
        string ...$projectTypePatterns
    ): JsonResponse {
        $quoteId = (int) $request->input('quote_id');

        DB::beginTransaction();
        try {
            $quote = DB::table($quoteTable)
                ->where('id', $quoteId)
                ->lockForUpdate()
                ->select(['id', 'client_id', 'pic_name', 'pic_email', 'pic_phone', 'pic_position'])
                ->first();

            if (!$quote) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quote not found'], 404);
            }

            $clientId = (int) ($quote->client_id ?? 0);
            if ($clientId <= 0) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Quote has no client_id'], 422);
            }

            $projectQuery = DB::table('projects_main')
                ->where('quote_id', $quoteId)
                ->where(function ($q) use ($quoteType, $projectTypePatterns) {
                    $q->where('quote_type', $quoteType)
                      ->orWhere(function ($inner) use ($projectTypePatterns) {
                          $inner->where(function ($nn) {
                              $nn->whereNull('quote_type')->orWhereRaw("TRIM(quote_type) = ''");
                          });
                          foreach ($projectTypePatterns as $pattern) {
                              $inner->orWhereRaw('LOWER(project_type) LIKE ?', [$pattern]);
                          }
                      });
                });
            $projectIds = $projectQuery->pluck('id')->map(fn($id) => (int) $id)->filter()->values()->toArray();

            $client = DB::table('client_company')
                ->where('company_id', $clientId)
                ->whereNull('deleted_at')
                ->select(['company_name', 'ssm_number', 'address', 'city', 'state', 'zip', 'tax_id_no_tin'])
                ->first();

            if (!$client) {
                DB::rollBack();
                return response()->json(['status' => 'error', 'message' => 'Client not found'], 404);
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
                    ->select(['full_name', 'email', 'mobile_number', 'position'])
                    ->first();
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
                    ->select(['full_name', 'email', 'mobile_number', 'position'])
                    ->first();
            }

            if (!$pic && $picName !== '') {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->where('full_name', $picName)
                    ->select(['full_name', 'email', 'mobile_number', 'position'])
                    ->first();
            }

            if (!$pic) {
                $pic = DB::table('client_pic')
                    ->where('company_id', $clientId)
                    ->whereNull('deleted_at')
                    ->where('status', 'assigned')
                    ->orderBy('pic_id')
                    ->select(['full_name', 'email', 'mobile_number', 'position'])
                    ->first();
            }

            if ($pic) {
                $picName     = (string) ($pic->full_name ?? $picName);
                $picEmail    = (string) ($pic->email ?? $picEmail);
                $picPhone    = (string) ($pic->mobile_number ?? $picPhone);
                $picPosition = (string) ($pic->position ?? $picPosition);
            }

            DB::table($quoteTable)->where('id', $quoteId)->update([
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
            $requestedDoIds      = array_values(array_filter(array_map('intval', $cascade['delivery_orders'] ?? [])));
            $requestedInvoiceIds = array_values(array_filter(array_map('intval', $cascade['invoices'] ?? [])));
            $requestedReceiptIds = array_values(array_filter(array_map('intval', $cascade['receipts'] ?? [])));
            $requestedJd14Ids    = array_values(array_filter(array_map('intval', $cascade['jd14'] ?? [])));
            $requestedInvoiceIds = array_values(array_unique(array_merge($requestedInvoiceIds, $requestedReceiptIds)));

            $updatedCounts = ['delivery_orders' => 0, 'invoices' => 0, 'receipts' => 0, 'jd14' => 0];

            if (!empty($requestedDoIds) || !empty($requestedInvoiceIds) || !empty($requestedJd14Ids)) {
                $allowedDoIds      = [];
                $allowedInvoiceIds = [];
                $allowedReceiptIds = [];
                $allowedJd14Ids    = [];

                if (!empty($projectIds)) {
                    $allowedDoIds = DB::table('do_details')
                        ->whereIn('project_id', $projectIds)->pluck('id')->map('intval')->toArray();

                    $allowedJd14Ids = DB::table('invoices_jd14form')
                        ->whereIn('project_id', $projectIds)->pluck('id')->map('intval')->toArray();

                    $invRows = DB::table('invoices')
                        ->whereIn('project_id', $projectIds)->select(['id', 'receipt_no'])->get();
                    foreach ($invRows as $inv) {
                        $invId = (int) $inv->id;
                        $allowedInvoiceIds[] = $invId;
                        if (!empty($inv->receipt_no)) {
                            $allowedReceiptIds[] = $invId;
                        }
                    }
                    $allowedInvoiceIds = array_values(array_unique($allowedInvoiceIds));
                    $allowedReceiptIds = array_values(array_unique($allowedReceiptIds));
                }

                $finalDoIds      = array_values(array_intersect($requestedDoIds, $allowedDoIds));
                $finalInvoiceIds = array_values(array_intersect($requestedInvoiceIds, $allowedInvoiceIds));
                $finalReceiptIds = array_values(array_intersect($requestedReceiptIds, $allowedReceiptIds));
                $finalJd14Ids    = array_values(array_intersect($requestedJd14Ids, $allowedJd14Ids));

                if (!empty($requestedDoIds) && empty($finalDoIds)) {
                    throw new \RuntimeException('Selected delivery orders are not related to this quote.');
                }
                if (!empty($requestedInvoiceIds) && empty($finalInvoiceIds)) {
                    throw new \RuntimeException('Selected invoices are not related to this quote.');
                }
                if (!empty($requestedReceiptIds) && empty($finalReceiptIds)) {
                    throw new \RuntimeException('Selected receipts are not related to this quote.');
                }
                if (!empty($requestedJd14Ids) && empty($finalJd14Ids)) {
                    throw new \RuntimeException('Selected JD14 forms are not related to this quote.');
                }

                if (!empty($finalDoIds)) {
                    $updatedCounts['delivery_orders'] = DB::table('do_details')
                        ->whereIn('id', $finalDoIds)
                        ->update([
                            'client_name'             => $client->company_name,
                            'client_address'          => $client->address,
                            'client_contact_name'     => $picName,
                            'client_contact_position' => $picPosition,
                            'client_contact_email'    => $picEmail,
                            'client_contact_phone'    => $picPhone,
                        ]);
                }

                if (!empty($finalInvoiceIds)) {
                    $updatedCounts['invoices'] = DB::table('invoices')
                        ->whereIn('id', $finalInvoiceIds)
                        ->update([
                            'client_id'              => $clientId,
                            'invoice_client_name'    => $client->company_name,
                            'invoice_client_ssm'     => $client->ssm_number,
                            'invoice_client_tin'     => $client->tax_id_no_tin ?? null,
                            'invoice_client_address' => $client->address,
                            'invoice_client_city'    => $client->city,
                            'invoice_client_state'   => $client->state,
                            'invoice_client_zip'     => $client->zip,
                            'invoice_pic_name'       => $picName,
                            'invoice_pic_phone'      => $picPhone,
                            'invoice_pic_email'      => $picEmail,
                            'invoice_pic_position'   => $picPosition,
                        ]);
                }

                if (!empty($finalReceiptIds)) {
                    $updatedCounts['receipts'] = count($finalReceiptIds);
                }

                if (!empty($finalJd14Ids)) {
                    $addressParts   = array_filter([
                        $client->address ?? '',
                        trim(($client->zip ?? '') . ' ' . ($client->city ?? '')),
                        $client->state ?? '',
                    ]);
                    $employerAddress = implode(', ', $addressParts);
                    $updatedCounts['jd14'] = DB::table('invoices_jd14form')
                        ->whereIn('id', $finalJd14Ids)
                        ->update([
                            'employer_name'    => $client->company_name,
                            'employer_address' => $employerAddress,
                        ]);
                }
            }

            $logMessage = "Synced client details for {$quoteType} quote ID #{$quoteId}";
            if (!empty($cascade)) {
                $logMessage .= " (cascade: DO {$updatedCounts['delivery_orders']}, INV {$updatedCounts['invoices']}, JD14 {$updatedCounts['jd14']})";
            }
            $this->auditLog->log($request, $logMessage);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Client details synced successfully.',
            'updated' => $updatedCounts,
        ]);
    }
}
