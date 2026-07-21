<?php

namespace App\Services\ProposalTemplates;

use App\Services\AuditLogService;
use App\Services\Pdf\PdfRenderer;
use App\Support\AppFilePaths;
use App\Support\ProposalTitleFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SpecialProposalTemplatePdfService extends PdfRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function pdfSpecial(Request $request, int $id)
    {
        $row = DB::table('proposal_template_special as sp')
            ->where('sp.id', $id)
            ->where('sp.is_deleted', 0)
            ->select(['sp.*'])
            ->first();

        if (!$row) {
            return response()->json(['status' => 'error', 'message' => 'Special proposal not found.'], 404);
        }

        $attachmentFk = $this->specialAttachmentForeignKey();
        $attachmentNameCol = $this->specialAttachmentNameColumn();
        $proposalMode = $this->proposalMode($row);

        $attachments = DB::table('proposal_special_attachments')
            ->where($attachmentFk, $id)
            ->orderBy('id')
            ->get();
        if ($proposalMode === 'write') {
            $attachments = collect();
        }

        $generatedAt = now();
        $html = $this->renderSpecialProposalPdf($row);
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $dompdf = $this->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);

        $pdfBytes = $dompdf->output();

        $this->auditLog->log($request, "Generated PDF for special proposal template #{$id}");

        $serviceTitleRaw = (string) ($row->service_title ?? 'Proposal');
        $serviceCodeRaw = trim((string) ($row->service_code ?? 'SPEC'));
        $trimmedTitle = substr($serviceTitleRaw, 0, 20);
        $trimmedTitle = preg_replace('/[^A-Za-z0-9]/', '_', $trimmedTitle) ?: 'Proposal';
        $baseName = $trimmedTitle . '_' . ($serviceCodeRaw !== '' ? $serviceCodeRaw : 'SPEC') . '_Proposal_Pack';

        // If no attachments, stream PDF inline
        if ($attachments->isEmpty()) {
            return response($pdfBytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$baseName}.pdf\"",
            ]);
        }

        // If attachments exist, package everything into a ZIP
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $zipPath = $tempDir . "/special-proposal-{$id}-" . time() . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response($pdfBytes, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$baseName}.pdf\"",
            ]);
        }

        if ($proposalMode === 'write') {
            $zip->addFromString("{$baseName}.pdf", $pdfBytes);
        }

        foreach ($attachments as $att) {
            $relativePath = AppFilePaths::publicStorageRelativePath($this->specialAttachmentStoredPath($att));
            if ($relativePath === null || $relativePath === '') {
                continue;
            }
            $diskPath = AppFilePaths::storedPathLocalPath($relativePath);
            $resolved = $diskPath ? realpath($diskPath) : false;
            if ($resolved === false || !is_file($resolved)) {
                continue;
            }
            $zipEntry = (string) ($att->{$attachmentNameCol} ?: basename($relativePath));
            // Strip any path components from the entry name to keep the archive flat.
            $zipEntry = basename($zipEntry);
            $ext = pathinfo($resolved, PATHINFO_EXTENSION);
            if ($ext !== '' && !str_ends_with(strtolower($zipEntry), '.' . strtolower($ext))) {
                $zipEntry .= '.' . $ext;
            }
            $zip->addFile($resolved, $zipEntry);
        }

        $zip->close();

        return response()->download($zipPath, "{$baseName}.zip", [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
    private function renderSpecialProposalPdf(object $proposal): string
    {
        $logoDataUri = $this->companyLogoDataUri();

        $proposalTitle = ProposalTitleFormatter::formatProposalTitle(
            (string) ($proposal->service_title ?? ''),
            'Service Proposal',
            'Service Proposal',
            'special-template.proposal-title',
        );
        $content = $this->proposalMode($proposal) === 'write'
            ? (string) ($proposal->proposal_content ?? $proposal->content ?? '')
            : '';
        $contentHtml = $this->toRenderableRichText($content);

        return view($this->pdfView('pdf.special-proposal', $proposal->proposal_language ?? 'en'), [
            'proposal' => $proposal,
            'proposalTitle' => trim($proposalTitle) !== '' ? trim($proposalTitle) : 'Service Proposal',
            'contentHtml' => $contentHtml,
            'logoDataUri' => $logoDataUri,
        ])->render();
    }

    private function specialAttachmentForeignKey(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
    }

    private function specialAttachmentNameColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'original_filename') ? 'original_filename' : 'file_name';
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

    private function proposalMode(object $proposal): string
    {
        return in_array($proposal->proposal_mode ?? null, ['upload', 'write'], true)
            ? $proposal->proposal_mode
            : 'upload';
    }

    private function hasColumn(string $table, string $column): bool
    {
        return app(ProposalTemplateCrudSupport::class)->hasColumn($table, $column);
    }
}
