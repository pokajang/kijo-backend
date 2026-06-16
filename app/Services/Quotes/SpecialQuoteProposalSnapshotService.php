<?php

namespace App\Services\Quotes;

use App\Support\AppFilePaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SpecialQuoteProposalSnapshotService
{
    public function capture(int $quoteId, int $templateId, string $language): array
    {
        if (
            $quoteId <= 0
            || $templateId <= 0
            || ! Schema::hasTable('quotes_special_proposal_snapshots')
            || ! Schema::hasTable('proposal_template_special')
        ) {
            return ['newPaths' => [], 'oldPaths' => []];
        }

        $template = DB::table('proposal_template_special')
            ->where('id', $templateId)
            ->where('is_deleted', 0)
            ->first();

        if (! $template) {
            return ['newPaths' => [], 'oldPaths' => []];
        }

        $oldSnapshot = DB::table('quotes_special_proposal_snapshots')
            ->where('quote_id', $quoteId)
            ->first();
        $oldPaths = $this->snapshotAttachmentPaths($oldSnapshot);

        $proposalMode = $this->proposalMode($template);
        $serviceSummary = (string) ($template->service_summary ?? ($proposalMode === 'upload' ? ($template->content ?? '') : ''));
        $proposalContent = (string) ($template->proposal_content ?? ($proposalMode === 'write' ? ($template->content ?? '') : ''));

        $attachments = $proposalMode === 'upload'
            ? $this->copyPdfAttachments($quoteId, $templateId)
            : ['attachments' => [], 'newPaths' => []];

        try {
            DB::table('quotes_special_proposal_snapshots')->where('quote_id', $quoteId)->delete();
            DB::table('quotes_special_proposal_snapshots')->insert([
                'quote_id' => $quoteId,
                'template_id' => $templateId,
                'proposal_language' => $language ?: 'en',
                'proposal_mode' => $proposalMode,
                'service_title' => $template->service_title ?? null,
                'service_code' => $template->service_code ?? null,
                'service_summary' => $serviceSummary,
                'proposal_content' => $proposalContent,
                'attachments_json' => json_encode($attachments['attachments']),
                'template_updated_at' => $template->updated_at ?? $template->created_at ?? null,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->deleteStoredPaths($attachments['newPaths']);
            throw $e;
        }

        return [
            'newPaths' => $attachments['newPaths'],
            'oldPaths' => $oldPaths,
        ];
    }

    public function deleteStoredPaths(array $paths): void
    {
        foreach ($paths as $path) {
            AppFilePaths::deleteStoredPath((string) $path);
        }
    }

    private function copyPdfAttachments(int $quoteId, int $templateId): array
    {
        if (! Schema::hasTable('proposal_special_attachments')) {
            return ['attachments' => [], 'newPaths' => []];
        }

        $attachmentFk = $this->specialAttachmentForeignKey();
        $nameColumn = $this->specialAttachmentNameColumn();
        $rows = DB::table('proposal_special_attachments')
            ->where($attachmentFk, $templateId)
            ->orderBy('id')
            ->get();

        $attachments = [];
        $newPaths = [];
        foreach ($rows as $row) {
            if (! $this->isPdfAttachment($row)) {
                continue;
            }

            $sourcePath = $this->attachmentStoredPath($row);
            $extension = strtolower(pathinfo((string) ($row->{$nameColumn} ?? ''), PATHINFO_EXTENSION)) ?: 'pdf';
            $targetPath = 'quote-proposals/special/'.$quoteId.'/'.uniqid('sp_snapshot_', true).'.'.$extension;
            if (! AppFilePaths::copyStoredPath($sourcePath, $targetPath)) {
                $this->deleteStoredPaths($newPaths);
                throw new RuntimeException('Failed to copy special proposal PDF attachment for quote snapshot.');
            }

            $newPaths[] = $targetPath;
            $attachments[] = [
                'sourceAttachmentId' => (int) $row->id,
                'fileName' => $row->{$nameColumn} ?? basename($targetPath),
                'storedPath' => $targetPath,
                'mimeType' => $row->mime_type ?? 'application/pdf',
                'fileSize' => $row->file_size ?? null,
            ];
        }

        return ['attachments' => $attachments, 'newPaths' => $newPaths];
    }

    private function snapshotAttachmentPaths(?object $snapshot): array
    {
        if (! $snapshot || empty($snapshot->attachments_json)) {
            return [];
        }

        $attachments = json_decode((string) $snapshot->attachments_json, true);
        if (! is_array($attachments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($attachment) => is_array($attachment) ? ($attachment['storedPath'] ?? null) : null,
            $attachments
        )));
    }

    private function proposalMode(object $template): string
    {
        return in_array($template->proposal_mode ?? null, ['upload', 'write'], true)
            ? $template->proposal_mode
            : 'upload';
    }

    private function isPdfAttachment(object $attachment): bool
    {
        $nameColumn = $this->specialAttachmentNameColumn();
        return strtolower((string) ($attachment->mime_type ?? '')) === 'application/pdf'
            || strtolower(pathinfo((string) ($attachment->{$nameColumn} ?? ''), PATHINFO_EXTENSION)) === 'pdf';
    }

    private function attachmentStoredPath(object $attachment): string
    {
        $pathColumn = $this->specialAttachmentPathColumn();
        $primary = trim((string) ($attachment->{$pathColumn} ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        foreach (['stored_path', 'file_url'] as $column) {
            if ($column === $pathColumn) {
                continue;
            }

            $fallback = trim((string) ($attachment->{$column} ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    private function specialAttachmentForeignKey(): string
    {
        return Schema::hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
    }

    private function specialAttachmentNameColumn(): string
    {
        return Schema::hasColumn('proposal_special_attachments', 'original_filename') ? 'original_filename' : 'file_name';
    }

    private function specialAttachmentPathColumn(): string
    {
        return Schema::hasColumn('proposal_special_attachments', 'stored_path') ? 'stored_path' : 'file_url';
    }
}
