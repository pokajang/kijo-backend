<?php

namespace App\Services\Quotes\Records;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordClientSyncService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function syncClientDetails(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (!$cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 422);
        }

        $quote = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        $clientId = (int) ($quote->client_id ?? 0);
        if ($clientId <= 0 || !$this->config->hasTable('client_company')) {
            return response()->json(['status' => 'error', 'message' => 'Quote has invalid client information.'], 422);
        }

        $client = DB::table('client_company')
            ->where('company_id', $clientId)
            ->when($this->config->hasColumn('client_company', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->first();
        if (!$client) {
            return response()->json(['status' => 'error', 'message' => 'Client not found.'], 404);
        }

        $picId = (int) $request->input('pic_id', 0);
        $pic = null;
        if ($this->config->hasTable('client_pic')) {
            $picQuery = DB::table('client_pic')
                ->where('company_id', $clientId)
                ->when($this->config->hasColumn('client_pic', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));
            if ($this->config->hasColumn('client_pic', 'status')) {
                $picQuery->where('status', 'assigned');
            }
            if ($picId > 0) {
                $pic = (clone $picQuery)->where('pic_id', $picId)->first();
            }
            if (!$pic) {
                $pic = (clone $picQuery)->orderBy('pic_id')->first();
            }
        }

        $picName = $pic->full_name ?? $quote->pic_name ?? null;
        $picEmail = $pic->email ?? $quote->pic_email ?? null;
        $picPhone = $pic->mobile_number ?? $quote->pic_phone ?? null;
        $picPosition = $pic->position ?? $quote->pic_position ?? null;

        DB::beginTransaction();
        try {
            $quoteUpdate = $this->config->filterColumns($cfg['table'], [
                'client_name' => $client->company_name ?? null,
                'client_ssm' => $client->ssm_number ?? null,
                'client_address' => $client->address ?? null,
                'client_city' => $client->city ?? null,
                'client_state' => $client->state ?? null,
                'client_zip' => $client->zip ?? null,
                'pic_name' => $picName,
                'pic_email' => $picEmail,
                'pic_phone' => $picPhone,
                'pic_position' => $picPosition,
                'updated_at' => now(),
            ]);
            if (!empty($quoteUpdate)) {
                DB::table($cfg['table'])->where('id', $quoteId)->update($quoteUpdate);
            }

            $updated = ['delivery_orders' => 0, 'invoices' => 0, 'receipts' => 0, 'jd14' => 0];
            $cascade = $request->input('cascade', []);
            if (is_array($cascade) && $this->config->hasTable('projects_main')) {
                $projectIds = $this->config->linkedProjectsBase($service)->where('quote_id', $quoteId)->pluck('id')->map(fn ($v) => (int) $v)->all();

                $doIds = array_values(array_filter(array_map('intval', $cascade['delivery_orders'] ?? [])));
                if (!empty($projectIds) && !empty($doIds) && $this->config->hasTable('do_details')) {
                    $payload = $this->config->filterColumns('do_details', [
                        'client_name' => $client->company_name ?? null,
                        'client_address' => $client->address ?? null,
                        'client_contact_name' => $picName,
                        'client_contact_position' => $picPosition,
                        'client_contact_email' => $picEmail,
                        'client_contact_phone' => $picPhone,
                    ]);
                    if (!empty($payload)) {
                        $count = DB::table('do_details')->whereIn('project_id', $projectIds)->whereIn('id', $doIds)->update($payload);
                        $updated['delivery_orders'] = $count;
                    }
                }

                $invoiceIds = array_values(array_filter(array_map('intval', array_merge($cascade['invoices'] ?? [], $cascade['receipts'] ?? []))));
                if (!empty($projectIds) && !empty($invoiceIds) && $this->config->hasTable('invoices')) {
                    $payload = $this->config->filterColumns('invoices', [
                        'client_id' => $clientId,
                        'invoice_client_name' => $client->company_name ?? null,
                        'invoice_client_ssm' => $client->ssm_number ?? null,
                        'invoice_client_address' => $client->address ?? null,
                        'invoice_client_city' => $client->city ?? null,
                        'invoice_client_state' => $client->state ?? null,
                        'invoice_client_zip' => $client->zip ?? null,
                        'invoice_pic_name' => $picName,
                        'invoice_pic_phone' => $picPhone,
                        'invoice_pic_email' => $picEmail,
                        'invoice_pic_position' => $picPosition,
                    ]);
                    if (!empty($payload)) {
                        $count = DB::table('invoices')->whereIn('project_id', $projectIds)->whereIn('id', $invoiceIds)->update($payload);
                        $updated['invoices'] = $count;
                        $updated['receipts'] = count(array_filter($cascade['receipts'] ?? []));
                    }
                }

                $jd14Ids = array_values(array_filter(array_map('intval', $cascade['jd14'] ?? [])));
                if (!empty($projectIds) && !empty($jd14Ids) && $this->config->hasTable('invoices_jd14form')) {
                    $employerAddress = trim(implode(', ', array_filter([
                        $client->address ?? null,
                        trim(($client->zip ?? '') . ' ' . ($client->city ?? '')),
                        $client->state ?? null,
                    ])));
                    $payload = $this->config->filterColumns('invoices_jd14form', [
                        'employer_name' => $client->company_name ?? null,
                        'employer_address' => $employerAddress,
                    ]);
                    if (!empty($payload)) {
                        $count = DB::table('invoices_jd14form')->whereIn('project_id', $projectIds)->whereIn('id', $jd14Ids)->update($payload);
                        $updated['jd14'] = $count;
                    }
                }
            }

            DB::commit();
            return response()->json([
                'status' => 'success',
                'message' => 'Client details synced successfully.',
                'updated' => $updated,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Server error.'], 500);
        }
    }
}
