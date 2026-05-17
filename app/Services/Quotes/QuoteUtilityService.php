<?php

namespace App\Services\Quotes;

use App\Http\Requests\Quote\SaveInquirySourceRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteUtilityService
{
    public function __construct(private AuditLogService $auditLog) {}

    public function listTrainingTopics(Request $request): JsonResponse
    {
        $query = DB::table('proposal_template_training_main')
            ->where('is_deleted', 0)
            ->select(['id', 'training_title', 'duration', 'proposal_language'])
            ->orderBy('training_title');

        if ($this->hasColumn('proposal_template_training_main', 'proposal_language')) {
            $language = $this->normalizeProposalLanguage($request->query('language', 'en'));
            $query->where('proposal_language', $language);
            if ($language === 'ms-MY' && $this->hasColumn('proposal_template_training_main', 'translation_status')) {
                $query->where(function ($statusQuery): void {
                    $statusQuery
                        ->whereNull('translation_status')
                        ->orWhere('translation_status', '<>', 'machine_draft');
                });
            }
        }

        $topics = $query->get();

        return response()->json(['status' => 'success', 'data' => $topics]);
    }

    public function saveInquirySource(SaveInquirySourceRequest $request): JsonResponse
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $data = $request->validated();
        $serviceType = $this->normalizeServiceType($data['service_type'] ?? '');
        if ($serviceType === null) {
            return response()->json(['status' => 'error', 'message' => 'Invalid service type.'], 422);
        }

        // Duplicate guard: same quote_id + service_type already exists
        $exists = DB::table('quote_inquiry_sources')
            ->where('quote_id', $data['quote_id'])
            ->where('service_type', $serviceType)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => 'info',
                'message' => 'Inquiry source already recorded.',
            ]);
        }

        try {
            $insertId = DB::table('quote_inquiry_sources')->insertGetId([
                'quote_id'     => $data['quote_id'],
                'quote_ref_no' => $data['quote_ref_no'] ?? null,
                'client_id'    => $data['client_id'],
                'service_type' => $serviceType,
                'source'       => $data['source'],
                'remarks'      => $data['remarks'] ?? null,
                'staff_id'     => $staffId,
                'created_by'   => (string) $request->session()->get('full_name', ''),
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => 'Database error.'], 500);
        }

        $this->auditLog->log($request, "Saved inquiry source for quote " . ($data['quote_ref_no'] ?? $data['quote_id']) . " ({$serviceType})");

        return response()->json([
            'status'  => 'success',
            'message' => 'Inquiry source saved.',
            'data'    => ['id' => $insertId],
        ]);
    }

    private function normalizeServiceKey(string $service): string
    {
        $service = strtolower(trim($service));
        return match ($service) {
            'equipment-tab', 'equipment supply', 'equipment' => 'equipment',
            'manpower-tab', 'manpower supply', 'manpower' => 'manpower',
            'ih-tab', 'industrial hygiene', 'ih' => 'ih',
            'special-tab', 'special service', 'special' => 'special',
            'training-tab', 'training' => 'training',
            default => $service,
        };
    }

    private function normalizeServiceType(string $serviceType): ?string
    {
        $normalized = $this->normalizeServiceKey($serviceType);
        return match ($normalized) {
            'equipment' => 'Equipment Supply',
            'manpower' => 'Manpower Supply',
            'ih' => 'Industrial Hygiene',
            'special' => 'Special Service',
            'training' => 'Training',
            default => null,
        };
    }

    private function normalizeProposalLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
