<?php

namespace App\Services\Quotes\Records;

use App\Services\Projects\ProjectCollaboratorAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuoteRecordAwardService
{
    public function __construct(private QuoteRecordConfig $config) {}

    public function changeQuoteToFail(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (! $cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        $remarks = trim((string) $request->input('remarks', ''));
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing quote_id.'], 422);
        }

        $row = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (! $row) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found.'], 404);
        }

        if (strtolower((string) ($row->status ?? '')) === 'failed') {
            return response()->json(['status' => 'error', 'message' => 'Quotation is already marked as Failed.']);
        }

        try {
            DB::table($cfg['table'])->where('id', $quoteId)->update([
                'status' => 'Failed',
                'status_remarks' => $remarks,
                'updated_at' => now(),
            ]);

            return response()->json(['status' => 'success', 'message' => 'Quotation marked as Failed.']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }
    }

    public function changeQuoteToSuccess(Request $request, string $service): JsonResponse
    {
        return $this->awardQuote($request, $service, false);
    }

    public function reAwardQuote(Request $request, string $service): JsonResponse
    {
        return $this->awardQuote($request, $service, true);
    }

    public function unAwardQuote(Request $request, string $service): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (! $cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 422);
        }

        $quote = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }

        if (strtolower((string) ($quote->status ?? '')) !== 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Only Awarded quotations can be un-awarded.'], 400);
        }

        DB::beginTransaction();
        try {
            $projects = $this->config->linkedProjectsBase($service)
                ->where('quote_id', $quoteId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();

            $target = $projects->first();
            if ($target) {
                $targetId = (int) $target->id;
                foreach (['invoices', 'do_details', 'project_vendors', 'vendor_payments'] as $table) {
                    if (! $this->config->hasTable($table) || ! $this->config->hasColumn($table, 'project_id')) {
                        continue;
                    }
                    $q = DB::table($table)->where('project_id', $targetId);
                    if ($table === 'vendor_payments' && $this->config->hasColumn($table, 'deleted_at')) {
                        $q->whereNull('deleted_at');
                    }
                    if ($q->count() > 0) {
                        throw new \RuntimeException("Cannot un-award. Linked project #{$targetId} has related records.");
                    }
                }

                foreach (['project_closing_details', 'project_collaborators', 'project_progress', 'project_vendors', 'project_expenses'] as $child) {
                    if ($this->config->hasTable($child) && $this->config->hasColumn($child, 'project_id')) {
                        DB::table($child)->where('project_id', $targetId)->delete();
                    }
                }
                DB::table('projects_main')->where('id', $targetId)->delete();
            }

            $remaining = $this->config->linkedProjectsBase($service)->where('quote_id', $quoteId)->count();
            if ($remaining > 0) {
                $latest = $this->config->linkedProjectsBase($service)
                    ->where('quote_id', $quoteId)
                    ->orderByDesc('award_date')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();
                DB::table($cfg['table'])->where('id', $quoteId)->update([
                    'status' => 'Awarded',
                    'status_remarks' => $remaining > 1 ? 'Re-Awarded' : 'Awarded',
                    'award_date' => $latest->award_date ?? null,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table($cfg['table'])->where('id', $quoteId)->update([
                    'status' => 'Open',
                    'status_remarks' => 'Un-awarded by user.',
                    'award_date' => null,
                    'client_award_ref_no' => null,
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $remaining > 0
                    ? "Latest award removed. Quotation remains Awarded with {$remaining} linked project(s)."
                    : 'Quotation un-awarded successfully.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    private function awardQuote(Request $request, string $service, bool $isReAward): JsonResponse
    {
        $service = $this->config->normalizeServiceKey($service);
        $cfg = $this->config->quoteConfig($service);
        if (! $cfg) {
            return response()->json(['status' => 'error', 'message' => 'Unsupported service type.'], 404);
        }

        $quoteId = (int) $request->input('quote_id', 0);
        $remarks = trim((string) $request->input('remarks', ''));
        $awardDate = (string) ($request->input('award_date') ?: date('Y-m-d'));
        $description = trim((string) ($request->input('description') ?? ''));
        $clientRefNo = $request->input('client_award_ref_no');

        if ($quoteId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Missing or invalid quote_id.'], 422);
        }

        $quote = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (! $quote) {
            return response()->json(['status' => 'error', 'message' => 'Quotation not found.'], 404);
        }

        if ($isReAward && strtolower((string) ($quote->status ?? '')) !== 'awarded') {
            return response()->json(['status' => 'error', 'message' => 'Only Awarded quotations can be re-awarded.'], 400);
        }

        if (! $isReAward && $this->config->linkedProjectsBase($service)->where('quote_id', $quoteId)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'This quotation is already linked to a project.'], 400);
        }

        DB::beginTransaction();
        try {
            DB::table($cfg['table'])->where('id', $quoteId)->update([
                'status' => 'Awarded',
                'status_remarks' => $remarks !== '' ? $remarks : ($isReAward ? 'Re-Awarded' : 'Awarded'),
                'award_date' => $awardDate,
                'client_award_ref_no' => $clientRefNo,
                'updated_at' => now(),
            ]);

            $projectId = $this->insertProjectFromQuote($service, $quoteId, $description, $awardDate, $clientRefNo);
            app(ProjectCollaboratorAssignmentService::class)->assignInitialCollaborators($projectId, $request);
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => ($isReAward ? 'Re-awarded' : 'Quotation awarded').' and project created successfully.',
                'project_id' => $projectId,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    private function insertProjectFromQuote(string $service, int $quoteId, string $description, string $awardDate, mixed $clientRefNo): int
    {
        $cfg = $this->config->quoteConfig($service);
        $quote = DB::table($cfg['table'])->where('id', $quoteId)->first();
        if (! $quote) {
            throw new \RuntimeException('Quote not found.');
        }

        $projectName = $cfg['project_type'];
        if ($service === 'equipment') {
            $itemNames = DB::table('quotes_equipment_items as qi')
                ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qi.item_id')
                ->where('qi.quote_id', $quoteId)
                ->orderBy('qi.id')
                ->limit(3)
                ->pluck('ci.item_name')
                ->filter()
                ->values()
                ->all();
            if (! empty($itemNames)) {
                $projectName .= ' - '.implode(', ', $itemNames);
            }
        } elseif ($service === 'training') {
            $projectName = (string) ($quote->training_title ?: $cfg['project_type']);
        } else {
            $projectName = (string) (($quote->service_title ?? '') ?: $cfg['project_type']);
        }

        $payload = [
            'client_id' => $quote->client_id ?? null,
            'quote_id' => $quoteId,
            'project_name' => $projectName,
            'project_type' => $cfg['project_type'],
            'quote_type' => $service,
            'po_loa_number' => $clientRefNo,
            'description' => $description !== '' ? $description : null,
            'status' => 'Active',
            'quote_value' => $quote->grand_total ?? 0,
            'proposal_language' => $this->config->normalizeProposalLanguage($quote->proposal_language ?? 'en'),
            'award_date' => $awardDate,
            'created_at' => now(),
        ];

        $filtered = $this->config->filterColumns('projects_main', $payload);
        if (empty($filtered)) {
            throw new \RuntimeException('Unable to write project record.');
        }

        return (int) DB::table('projects_main')->insertGetId($filtered);
    }
}
