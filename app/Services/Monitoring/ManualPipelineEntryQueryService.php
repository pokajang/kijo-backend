<?php

namespace App\Services\Monitoring;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ManualPipelineEntryQueryService extends ManualPipelineEntryBaseService
{

    public function list(Request $request, ?string $start, ?string $end, array $staffFilter): array
    {
        $staffCode = $staffFilter['code'];
        $entryType = trim((string) $request->input('entry_type', ''));
        $source = trim((string) $request->input('source', ''));
        $segmentType = trim((string) $request->input('segment_type', ''));
        $serviceCategory = $this->normalizeServiceCategory($request->input('service_category'));
        $search = trim((string) $request->input('q', ''));

        $query = DB::table('monitoring_manual_pipeline_entries')
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($start && $end) {
            $query->whereBetween('entry_date', [$start, $end]);
        }

        if ($staffCode !== null) {
            $query->whereRaw('UPPER(owner_staff_code) = ?', [$staffCode]);
        }

        if ($entryType !== '') {
            $query->where('entry_type', $entryType);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($segmentType !== '') {
            $query->where('segment_type', $segmentType);
        }

        if ($serviceCategory !== null) {
            $query->where('service_category', $serviceCategory);
        }

        if ($search !== '') {
            $query->where(function ($nested) use ($search) {
                $nested
                    ->where('prospect_name', 'like', '%' . $search . '%')
                    ->orWhere('notes', 'like', '%' . $search . '%')
                    ->orWhere('owner_staff_code', 'like', '%' . $search . '%')
                    ->orWhere('owner_staff_name', 'like', '%' . $search . '%');
            });
        }

        return $query->limit(1000)->get()->map(fn($entry) => $this->mapEntry($request, $entry))->all();
    }

    public function find(Request $request, int $id): ?array
    {
        $entry = DB::table('monitoring_manual_pipeline_entries')->where('id', $id)->first();
        if (!$entry || !$this->canViewEntry($request, $entry)) {
            return null;
        }

        return $this->mapEntry($request, $entry);
    }

    private function mapEntry(Request $request, $entry): array
    {
        $canManage = $this->canManageEntry($request, $entry);

        return [
            'id' => (int) $entry->id,
            'recordSource' => 'manual',
            'legalAssessmentId' => null,
            'entryType' => (string) $entry->entry_type,
            'prospectName' => (string) $entry->prospect_name,
            'entryDate' => (string) $entry->entry_date,
            'source' => (string) ($entry->source ?? ''),
            'segmentType' => (string) ($this->normalizeClassification($entry->segment_type) ?? ''),
            'serviceCategory' => (string) ($this->normalizeServiceCategory($entry->service_category) ?? ''),
            'estimatedRm' => $entry->estimated_rm !== null ? (float) $entry->estimated_rm : null,
            'notes' => (string) ($entry->notes ?? ''),
            'photoUrl' => !empty($entry->photo_path)
                ? $this->photoUrl((int) $entry->id)
                : null,
            'photoOriginalName' => (string) ($entry->photo_original_name ?? ''),
            'ownerStaffCode' => (string) ($entry->owner_staff_code ?? ''),
            'ownerStaffName' => (string) ($entry->owner_staff_name ?? ''),
            'createdByCode' => (string) ($entry->created_by_code ?? ''),
            'createdAt' => (string) ($entry->created_at ?? ''),
            'canUpdate' => $canManage,
            'canDelete' => $canManage,
        ];
    }

    public function entriesTableReady(): bool
    {
        if (!Schema::hasTable('monitoring_manual_pipeline_entries')) {
            return false;
        }

        $requiredColumns = [
            'entry_type',
            'prospect_name',
            'entry_date',
            'source',
            'segment_type',
            'service_category',
            'estimated_rm',
            'notes',
            'photo_path',
            'photo_original_name',
            'photo_mime_type',
            'owner_staff_id',
            'owner_staff_code',
            'owner_staff_name',
            'created_by',
            'created_by_code',
        ];

        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn('monitoring_manual_pipeline_entries', $column)) {
                return false;
            }
        }

        return true;
    }
}
