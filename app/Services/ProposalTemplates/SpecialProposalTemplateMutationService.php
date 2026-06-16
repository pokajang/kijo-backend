<?php

namespace App\Services\ProposalTemplates;

use App\Http\Requests\ProposalTemplate\StoreTrainingProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateTrainingProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreManpowerProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateManpowerProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreIhProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateIhProposalRequest;
use App\Http\Requests\ProposalTemplate\StoreSpecialProposalRequest;
use App\Http\Requests\ProposalTemplate\UpdateSpecialProposalRequest;
use App\Services\AuditLogService;
use App\Services\Translation\TranslationException;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SpecialProposalTemplateMutationService
{
    private static bool $dompdfAutoloaderRegistered = false;
    private static array $columnExistsCache = [];

    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function storeSpecial(StoreSpecialProposalRequest $request)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);
        $proposalMode = $data['proposalMode'] ?? 'upload';
        $serviceSummary = $data['serviceSummary'] ?? '';
        $proposalContent = $data['proposalContent'] ?? '';
        $content = $proposalMode === 'write' ? $proposalContent : $serviceSummary;
        $defaultLineItems = $this->normalizeDefaultLineItems($data['defaultLineItems'] ?? []);

        $uploadedPaths = [];

        try {
            DB::beginTransaction();

            $id = DB::table('proposal_template_special')->insertGetId($this->filterExistingColumns('proposal_template_special', [
                'service_title' => $data['serviceTitle'],
                'service_code'  => $data['serviceCode'],
                'proposal_mode' => $proposalMode,
                'service_summary' => $serviceSummary,
                'proposal_content' => $proposalContent,
                'content'       => $content,
                'created_by'    => $staffId,
                'is_deleted'    => 0,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]));

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $originalName  = $file->getClientOriginalName();
                    $ext           = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
                    $storedName    = uniqid('sp_att_', true) . '.' . $ext;
                    $storedPath    = "proposal-templates/special/{$id}/{$storedName}";

                    AppFilePaths::put($storedPath, file_get_contents($file->getRealPath()));
                    $uploadedPaths[] = $storedPath;

                    $this->insertSpecialAttachment($id, $originalName, $storedPath, $file->getMimeType(), $file->getSize());
                }
            }

            $this->replaceDefaultLineItems($id, $defaultLineItems, $staffId);

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? 'Proposal first created - ' . trim((string) $data['remarks'])
                : null;
            $this->insertTemplateHistory('proposal_template_special_history', $id, $remarks, $staffId, 'Created');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            // Clean up any uploaded files on failure
            foreach ($uploadedPaths as $path) {
                try { AppFilePaths::deleteStoredPath($path); } catch (\Throwable) {}
            }
            report($e);
            return $this->errorResponse($legacy, 'Failed to create special proposal.', 500);
        }

        $this->auditLog->log($request, "Created special proposal template #{$id} \"{$data['serviceTitle']}\" by {$staffCode}");

        if ($legacy) {
            return response()->json([
                'success' => true,
                'message' => 'Special proposal created successfully.',
                'id'      => $id,
            ], 201);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Special proposal created successfully.',
            'id'      => $id,
        ], 201);
    }

    public function updateSpecial(UpdateSpecialProposalRequest $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);
        $attachmentFk = $this->specialAttachmentForeignKey();
        $proposalMode = $data['proposalMode'] ?? 'upload';
        $serviceSummary = $data['serviceSummary'] ?? '';
        $proposalContent = $data['proposalContent'] ?? '';
        $content = $proposalMode === 'write' ? $proposalContent : $serviceSummary;
        $defaultLineItems = $this->normalizeDefaultLineItems($data['defaultLineItems'] ?? []);

        $row = DB::table('proposal_template_special')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Special proposal not found.', 404);
        }

        if ($proposalMode === 'upload') {
            $removeIds = array_map('intval', (array) ($data['removeAttachmentIds'] ?? []));
            $retainedPdfCount = DB::table('proposal_special_attachments')
                ->where($attachmentFk, $id)
                ->when(! empty($removeIds), fn ($query) => $query->whereNotIn('id', $removeIds))
                ->get()
                ->filter(fn ($att) => $this->isPdfAttachment($att))
                ->count();
            $newPdfCount = $request->hasFile('attachments') ? count($request->file('attachments')) : 0;
            if (($retainedPdfCount + $newPdfCount) <= 0) {
                return $this->errorResponse($legacy, 'At least one PDF attachment is required in upload mode.', 422);
            }
        }

        $uploadedPaths = [];
        $pathsToDelete = [];

        try {
            DB::beginTransaction();

            $update = [
                'service_title' => $data['serviceTitle'],
                'service_code'  => $data['serviceCode'],
                'proposal_mode' => $proposalMode,
                'service_summary' => $serviceSummary,
                'proposal_content' => $proposalContent,
                'content'       => $content,
                'updated_at'    => now(),
            ];
            $this->markReviewedBmDraftOnUpdate('proposal_template_special', $row, $update);

            DB::table('proposal_template_special')->where('id', $id)->update($this->filterExistingColumns('proposal_template_special', $update));

            // Handle attachment removals
            $removeIds = array_map('intval', (array) ($data['removeAttachmentIds'] ?? []));
            if ($proposalMode === 'write') {
                $removeIds = DB::table('proposal_special_attachments')
                    ->where($attachmentFk, $id)
                    ->pluck('id')
                    ->map(fn ($value) => (int) $value)
                    ->all();
            }

            if (!empty($removeIds)) {
                $toRemove  = DB::table('proposal_special_attachments')
                    ->where($attachmentFk, $id)
                    ->whereIn('id', $removeIds)
                    ->get();

                foreach ($toRemove as $att) {
                    $pathsToDelete[] = $this->specialAttachmentStoredPath($att);
                }

                DB::table('proposal_special_attachments')
                    ->where($attachmentFk, $id)
                    ->whereIn('id', $removeIds)
                    ->delete();
            }

            // Handle new file uploads
            if ($proposalMode === 'upload' && $request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $originalName = $file->getClientOriginalName();
                    $ext          = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
                    $storedName   = uniqid('sp_att_', true) . '.' . $ext;
                    $storedPath   = "proposal-templates/special/{$id}/{$storedName}";

                    AppFilePaths::put($storedPath, file_get_contents($file->getRealPath()));
                    $uploadedPaths[] = $storedPath;

                    $this->insertSpecialAttachment($id, $originalName, $storedPath, $file->getMimeType(), $file->getSize());
                }
            }

            $this->replaceDefaultLineItems($id, $defaultLineItems, $staffId);

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? trim((string) $data['remarks'])
                : null;

            $this->insertTemplateHistory('proposal_template_special_history', $id, $remarks, $staffId, 'Updated');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            foreach ($uploadedPaths as $path) {
                try { AppFilePaths::deleteStoredPath($path); } catch (\Throwable) {}
            }
            report($e);
            return $this->errorResponse($legacy, 'Failed to update special proposal.', 500);
        }

        // Delete removed attachment files after successful commit
        foreach ($pathsToDelete as $path) {
            $this->deleteSpecialAttachmentPath($path);
        }

        $this->auditLog->log($request, "Updated special proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Special proposal updated successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Special proposal updated successfully.']);
    }

    public function destroySpecial(Request $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $legacy    = $this->isLegacyPhpRoute($request);

        $row = DB::table('proposal_template_special')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Special proposal not found.', 404);
        }

        $inUseQuote = DB::table('quotes_special')
            ->select('id', 'quote_ref_no', 'status')
            ->where('sp_id', $id)
            ->where('attach_proposal', 1)
            ->first();

        if ($inUseQuote) {
            return $this->errorResponse($legacy, $this->templateInUseMessage($inUseQuote), 409);
        }

        $attachmentFk = $this->specialAttachmentForeignKey();
        $attachmentPaths = DB::table('proposal_special_attachments')
            ->where($attachmentFk, $id)
            ->get()
            ->map(fn ($att) => $this->specialAttachmentStoredPath($att))
            ->all();

        try {
            DB::beginTransaction();

            DB::table('proposal_template_special')->where('id', $id)->update($this->filterExistingColumns('proposal_template_special', [
                'is_deleted' => 1,
                'deleted_at' => now(),
                'deleted_by' => $staffId > 0 ? $staffId : null,
            ]));

            DB::table('proposal_special_attachments')->where($attachmentFk, $id)->delete();

            $this->insertTemplateHistory('proposal_template_special_history', $id, null, $staffId, 'Deleted');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to delete special proposal.', 500);
        }

        foreach ($attachmentPaths as $path) {
            $this->deleteSpecialAttachmentPath($path);
        }

        $this->auditLog->log($request, "Deleted special proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Special proposal deleted successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Special proposal deleted successfully.']);
    }

    private function errorResponse(bool $legacy, string $message, int $status)
    {
        if ($legacy) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
    private function filterExistingColumns(string $table, array $payload): array
    {
        return app(ProposalTemplateCrudSupport::class)->filterExistingColumns($table, $payload);
    }

    private function insertSpecialAttachment(int $templateId, string $originalName, string $storedPath, ?string $mimeType, ?int $fileSize): void
    {
        $payload = [
            $this->specialAttachmentForeignKey() => $templateId,
            $this->specialAttachmentNameColumn() => $originalName,
            $this->specialAttachmentPathColumn() => $storedPath,
            'mime_type'                          => $mimeType ?: 'application/octet-stream',
            'file_size'                          => $fileSize,
            'created_at'                         => now(),
        ];

        DB::table('proposal_special_attachments')->insert(
            $this->filterExistingColumns('proposal_special_attachments', $payload)
        );
    }

    private function insertTemplateHistory(string $table, int $templateId, ?string $remarks, int $staffId, string $action): void
    {
        $payload = [
            'template_id' => $templateId,
            'remarks'     => $remarks,
            'created_by'  => (string) $staffId,
            'created_at'  => now(),
        ];

        if ($this->hasColumn($table, 'action')) {
            $payload['action'] = $action;
        }

        DB::table($table)->insert($this->filterExistingColumns($table, $payload));
    }

    private function isLegacyPhpRoute(Request $request): bool
    {
        return str_contains($request->path(), '.php');
    }

    private function deleteSpecialAttachmentPath(mixed $path): void
    {
        $relativePath = AppFilePaths::publicStorageRelativePath((string) $path);
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        try {
            AppFilePaths::deleteStoredPath($relativePath);
        } catch (\Throwable) {
        }
    }

    private function markReviewedBmDraftOnUpdate(string $table, object $row, array &$payload): void
    {
        if (
            !$this->hasColumn($table, 'translation_status')
            || $this->normalizeProposalLanguage($row->proposal_language ?? 'en') !== 'ms-MY'
            || ($row->translation_status ?? null) !== 'machine_draft'
        ) {
            return;
        }

        $payload['translation_status'] = 'reviewed';
        $payload['translation_notes'] = trim((string) ($row->translation_notes ?? '')) !== ''
            ? trim((string) $row->translation_notes) . ' Reviewed and saved by staff.'
            : 'Reviewed and saved by staff.';
    }

    private function specialAttachmentForeignKey(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
    }

    private function specialAttachmentPathColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'stored_path') ? 'stored_path' : 'file_url';
    }

    private function specialAttachmentStoredPath(object $att): string
    {
        $pathCol = $this->specialAttachmentPathColumn();
        $primary = trim((string) ($att->{$pathCol} ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        foreach (['stored_path', 'file_url'] as $fallbackCol) {
            if ($fallbackCol === $pathCol) {
                continue;
            }

            $fallback = trim((string) ($att->{$fallbackCol} ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    private function templateInUseMessage(object $quote): string
    {
        $quoteRef = trim((string) ($quote->quote_ref_no ?? ''));
        $quoteLabel = $quoteRef !== '' ? $quoteRef : ('#' . (string) ($quote->id ?? ''));
        $status = trim((string) ($quote->status ?? ''));
        $statusText = $status !== '' ? " ({$status})" : '';

        return "Cannot delete: this proposal template is attached to quote {$quoteLabel}{$statusText}.";
    }
    private function hasColumn(string $table, string $column): bool
    {
        return app(ProposalTemplateCrudSupport::class)->hasColumn($table, $column);
    }

    private function specialAttachmentNameColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'original_filename') ? 'original_filename' : 'file_name';
    }
    private function normalizeProposalLanguage(mixed $language): string
    {
        return app(ProposalTemplateCrudSupport::class)->normalizeProposalLanguage($language);
    }

    private function normalizeDefaultLineItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['item_name'] ?? $item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? $item['unitPrice'] ?? 0);
            $quantity = $quantity > 0 ? $quantity : 1;
            $unitPrice = max(0, $unitPrice);

            $normalized[] = [
                'line_item_title' => $title,
                'description' => $item['description'] ?? null,
                'unit' => $item['unit'] ?? null,
                'default_quantity' => $quantity,
                'default_unit_price' => $unitPrice,
                'default_line_total' => round($quantity * $unitPrice, 2),
                'sort_order' => (int) ($item['sortOrder'] ?? $item['sort_order'] ?? $index),
            ];
        }

        usort($normalized, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return array_values($normalized);
    }

    private function replaceDefaultLineItems(int $templateId, array $items, int $staffId): void
    {
        if (! $this->hasTable('proposal_template_special_items')) {
            return;
        }

        DB::table('proposal_template_special_items')->where('template_id', $templateId)->delete();
        if (empty($items)) {
            return;
        }

        $now = now();
        $payload = [];
        foreach ($items as $index => $item) {
            $payload[] = [
                'template_id' => $templateId,
                'line_item_title' => $item['line_item_title'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'default_quantity' => $item['default_quantity'],
                'default_unit_price' => $item['default_unit_price'],
                'default_line_total' => $item['default_line_total'],
                'sort_order' => $index,
                'created_by' => $staffId > 0 ? $staffId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('proposal_template_special_items')->insert(
            array_map(fn ($row) => $this->filterExistingColumns('proposal_template_special_items', $row), $payload)
        );
    }

    private function isPdfAttachment(object $attachment): bool
    {
        $nameColumn = $this->specialAttachmentNameColumn();
        return strtolower((string) ($attachment->mime_type ?? '')) === 'application/pdf'
            || strtolower(pathinfo((string) ($attachment->{$nameColumn} ?? ''), PATHINFO_EXTENSION)) === 'pdf';
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
