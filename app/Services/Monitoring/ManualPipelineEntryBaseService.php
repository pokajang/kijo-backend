<?php

namespace App\Services\Monitoring;

use App\Support\AppFilePaths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

abstract class ManualPipelineEntryBaseService
{
    protected const SERVICE_CATEGORIES = [
        'training' => 'TRAINING',
        'consultancy_iso' => 'CONSULTANCY -ISO',
        'consultancy_ihoh' => 'CONSULTANCY - IHOH',
        'man_power' => 'MAN POWER',
        'equipment_supply' => 'EQUIPMENT SUPPLY',
        'engineering' => 'ENGINEERING',
        'infrastructure' => 'INFRASTRUCTURE',
    ];
    protected function entriesTableReady(): bool
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

    protected function validateClosedRevenue(array $entry): ?string
    {
        if (($entry['entry_type'] ?? null) !== 'closed') {
            return null;
        }

        if ($this->normalizeServiceCategory($entry['service_category'] ?? null) === null) {
            return 'Closed manual entries require a service category.';
        }

        $estimatedRm = $this->normalizeEstimatedRm($entry['estimated_rm'] ?? null);
        if ($estimatedRm === null || $estimatedRm <= 0) {
            return 'Closed manual entries require Estimated RM greater than zero.';
        }

        return null;
    }

    protected function normalizeClassification($segmentType): ?string
    {
        $segmentType = trim((string) ($segmentType ?? ''));

        if ($segmentType === '' || $segmentType === 'individual') {
            return null;
        }

        return $segmentType;
    }

    protected function normalizeClosedClassification(string $entryType, $segmentType): ?string
    {
        $normalized = $this->normalizeClassification($segmentType);

        return $entryType === 'closed' && $normalized === null ? 'individual' : $normalized;
    }

    protected function normalizeServiceCategory($serviceCategory): ?string
    {
        $serviceCategory = trim((string) ($serviceCategory ?? ''));

        return array_key_exists($serviceCategory, self::SERVICE_CATEGORIES)
            ? $serviceCategory
            : null;
    }

    protected function normalizeEstimatedRm($estimatedRm): ?float
    {
        if ($estimatedRm === null || $estimatedRm === '') {
            return null;
        }

        return is_numeric($estimatedRm) ? max(0.0, (float) $estimatedRm) : null;
    }

    protected function storePhoto(Request $request, int $index): array
    {
        $file = $request->file("photos.{$index}");
        if (!$file || !$file->isValid()) {
            return [
                'path' => null,
                'originalName' => null,
                'mimeType' => null,
            ];
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $filename = (string) Str::uuid() . '.' . $extension;
        $folder = 'monitoring-manual-entries/' . now()->format('Y/m');
        AppFilePaths::storeFileAs($folder, $file, $filename);

        return [
            'path' => $folder . '/' . $filename,
            'originalName' => $file->getClientOriginalName(),
            'mimeType' => $file->getMimeType(),
        ];
    }

    protected function photoUrl(int $id): string
    {
        return url('stats/monitoring-manual-pipeline-entry/' . $id . '/photo');
    }

    protected function canViewEntry(Request $request, $entry): bool
    {
        if ($this->canViewOtherStaff($request)) {
            return true;
        }

        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId > 0 && (
            (int) ($entry->created_by ?? 0) === $staffId ||
            (int) ($entry->owner_staff_id ?? 0) === $staffId
        )) {
            return true;
        }

        $staffCode = $this->sessionStaffCode($request);

        return $staffCode !== null
            && strtoupper((string) ($entry->owner_staff_code ?? '')) === $staffCode;
    }

    protected function resolveOwner(Request $request, $requestedCode): array
    {
        $requestedStaffCode = $this->normalizeStaffCode($requestedCode);
        $sessionStaffCode = $this->sessionStaffCode($request);

        if ($requestedStaffCode !== null && !$this->canViewOtherStaff($request)) {
            if ($sessionStaffCode === null || $requestedStaffCode !== $sessionStaffCode) {
                return ['forbidden' => true];
            }
        }

        $staffCode = $requestedStaffCode ?: $sessionStaffCode;
        if ($staffCode === null) {
            return [
                'forbidden' => false,
                'staffId' => (int) $request->session()->get('staff_id', 0),
                'staffCode' => trim((string) $request->session()->get('name_code', '')),
                'staffName' => trim((string) $request->session()->get('full_name', '')),
            ];
        }

        $staff = DB::table('staff_general')
            ->whereRaw('UPPER(name_code) = ?', [$staffCode])
            ->select('staff_id', 'name_code', 'full_name')
            ->first();

        if ($staff) {
            return [
                'forbidden' => false,
                'staffId' => (int) $staff->staff_id,
                'staffCode' => strtoupper((string) $staff->name_code),
                'staffName' => trim((string) ($staff->full_name ?? '')),
            ];
        }

        $quoteStaff = $this->baseQuoteFactsQuery()
            ->whereRaw('UPPER(staff_code) = ?', [$staffCode])
            ->select('staff_id', 'staff_code', 'staff_name')
            ->first();

        if ($quoteStaff) {
            return [
                'forbidden' => false,
                'staffId' => (int) ($quoteStaff->staff_id ?? 0),
                'staffCode' => strtoupper((string) ($quoteStaff->staff_code ?? $staffCode)),
                'staffName' => trim((string) ($quoteStaff->staff_name ?? '')),
            ];
        }

        if ($requestedStaffCode !== null) {
            return [
                'forbidden' => false,
                'invalid' => true,
                'staffId' => 0,
                'staffCode' => $staffCode,
                'staffName' => '',
            ];
        }

        return [
            'forbidden' => false,
            'staffId' => 0,
            'staffCode' => $staffCode,
            'staffName' => '',
        ];
    }

    protected function canViewOtherStaff(Request $request): bool
    {
        foreach ($this->sessionRoles($request) as $role) {
            $roleText = strtolower($role);
            if (
                str_contains($roleText, 'manager') ||
                str_contains($roleText, 'hr') ||
                str_contains($roleText, 'admin') ||
                str_contains($roleText, 'super')
            ) {
                return true;
            }
        }

        return false;
    }

    protected function sessionRoles(Request $request): array
    {
        $roles = $request->session()->get('roles', []);
        if (!is_array($roles)) {
            $roles = $roles ? [$roles] : [];
        }

        return array_values(array_filter(array_map(
            static fn($role) => trim((string) $role),
            $roles
        )));
    }

    protected function sessionStaffCode(Request $request): ?string
    {
        return $this->normalizeStaffCode($request->session()->get('name_code'));
    }

    protected function normalizeStaffCode($value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '' || $code === 'ALL') {
            return null;
        }

        return $code;
    }

    protected function baseQuoteFactsQuery()
    {
        $base = DB::table('all_quotes')
            ->selectRaw("
                service_group,
                quote_id,
                MAX(created_at) AS created_at,
                MAX(award_date) AS award_date,
                MAX(staff_id) AS staff_id,
                MAX(staff_name) AS staff_name,
                MAX(staff_code) AS staff_code,
                MAX(client_id) AS client_id,
                MAX(client_name) AS client_name,
                MAX(quote_status) AS quote_status,
                MAX(value) AS value,
                MAX(inquiry_source) AS inquiry_source
            ")
            ->groupBy('service_group', 'quote_id');

        return DB::query()->fromSub($base, 'quote_facts');
    }
}
