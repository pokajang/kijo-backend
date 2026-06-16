<?php

namespace App\Services\Quotes\Pdf;

use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialQuotePdfService
{
    public function __construct(
        private AuditLogService $auditLog,
        private QuotePdfRenderer $renderer,
        private PdfMergeService $pdfMerge
    ) {}

    public function generate(Request $request, int $quoteId)
    {
        $quote = DB::table('quotes_special as qs')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'qs.created_by_id')
            ->where('qs.id', $quoteId)
            ->select([
                'qs.*',
                'sg.position as staff_position',
                'sg.crm_position as crm_position',
                'sg.department as staff_department',
            ])
            ->first();

        if (!$quote) {
            return response()->json(['status' => 'error', 'message' => 'Quote not found'], 404);
        }

        $signOffTitle = !empty($quote->crm_position)
            ? (string) $quote->crm_position
            : trim(((string) ($quote->staff_position ?? '')) . ' (' . ((string) ($quote->staff_department ?? '')) . ')');
        if ($signOffTitle === '()' || $signOffTitle === '') {
            $signOffTitle = 'Staff';
        }

        $items = DB::table('quotes_special_items')
            ->where('quote_id', $quoteId)
            ->orderBy('id')
            ->select(['line_item_title as title', 'description', 'unit', 'unit_price', 'quantity', 'line_total'])
            ->get();

        $discountAmount = (float) ($quote->discount ?? ($quote->discount_amount ?? 0));
        $subTotalNet = (float) ($quote->sub_total ?? 0);
        $sstPercent = (float) ($quote->sst_percent ?? 0);
        $sstAmount = (float) ($quote->sst_amount ?? 0);
        $grandTotal = (float) ($quote->grand_total ?? 0);
        $grossAmount = $subTotalNet + $discountAmount;
        $showSubtotal = $discountAmount > 0 && $sstAmount > 0;
        $sstPercentLabel = ((float) (int) $sstPercent === $sstPercent)
            ? number_format($sstPercent, 0)
            : number_format($sstPercent, 2);

        $createdDateLegacy = '';
        if (!empty($quote->created_at)) {
            $ts = strtotime((string) $quote->created_at);
            if ($ts !== false) {
                $createdDateLegacy = date('d M Y', $ts);
            }
        }

        $remarksText = trim((string) ($quote->general_remarks ?? ''));
        $remarksHtml = $remarksText !== '' ? nl2br(e($remarksText)) : '';

        $appendProposal = (int) ($quote->attach_proposal ?? 0) === 1 && (int) ($quote->sp_id ?? 0) > 0;
        $appendProposalContent = false;
        $proposalServiceTitle = '';
        $proposalTitle = '';
        $proposalContentHtml = '';
        $proposalPdfAttachmentPaths = [];
        if ($appendProposal) {
            $snapshot = Schema::hasTable('quotes_special_proposal_snapshots')
                ? DB::table('quotes_special_proposal_snapshots')->where('quote_id', $quoteId)->first()
                : null;

            if ($snapshot) {
                $proposalServiceTitle = trim((string) ($snapshot->service_title ?? ''));
                $proposalTitle = $this->specialProposalTitle($proposalServiceTitle);
                $proposalMode = in_array($snapshot->proposal_mode ?? null, ['upload', 'write'], true)
                    ? $snapshot->proposal_mode
                    : 'upload';

                if ($proposalMode === 'upload') {
                    foreach ($this->snapshotAttachmentPaths($snapshot) as $storedPath) {
                        $resolved = $this->resolveStoredPdfPath($storedPath);
                        if ($resolved !== null) {
                            $proposalPdfAttachmentPaths[] = $resolved;
                        }
                    }
                } else {
                    $proposalContentHtml = $this->renderer->toRenderableRichText((string) ($snapshot->proposal_content ?? ''));
                    $appendProposalContent = !empty(trim(strip_tags($proposalContentHtml)));
                }
            } else {
                $specialProposal = DB::table('proposal_template_special')
                    ->where('id', (int) $quote->sp_id)
                    ->where('is_deleted', 0)
                    ->first();

                if ($specialProposal) {
                    $proposalServiceTitle = trim((string) ($specialProposal->service_title ?? ''));
                    $proposalTitle = $this->specialProposalTitle($proposalServiceTitle);

                    $attachmentFk = $this->specialAttachmentForeignKey();
                    $attachments = DB::table('proposal_special_attachments')
                        ->where($attachmentFk, (int) $specialProposal->id)
                        ->orderBy('id')
                        ->get();
                    $resolvedAttachmentPaths = [];

                    foreach ($attachments as $attachment) {
                        $resolved = $this->resolveStoredPdfPath($this->specialAttachmentStoredPath($attachment));
                        if ($resolved !== null) {
                            $resolvedAttachmentPaths[] = $resolved;
                        }
                    }

                    $proposalMode = in_array($specialProposal->proposal_mode ?? null, ['upload', 'write'], true)
                        ? $specialProposal->proposal_mode
                        : (! empty($resolvedAttachmentPaths) ? 'upload' : 'write');

                    if ($proposalMode === 'upload') {
                        $proposalPdfAttachmentPaths = $resolvedAttachmentPaths;
                    }

                    if ($proposalMode === 'write') {
                        $proposalContent = (string) ($specialProposal->proposal_content ?? $specialProposal->content ?? '');
                        $proposalContentHtml = $this->renderer->toRenderableRichText($proposalContent);
                        $appendProposalContent = !empty(trim(strip_tags($proposalContentHtml)));
                    }
                }
            }
        }

        $generatedAt = now();
        $generatorId = (string) $request->session()->get('staff_id', 'Unknown');
        $generatorCode = (string) $request->session()->get('name_code', '');
        $logoDataUri = $this->renderer->companyLogoDataUri();

        $html = view($this->renderer->pdfView('pdf.special-quote', $quote->proposal_language ?? 'en'), [
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo' => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy' => $createdDateLegacy,
            'createdDateIso' => !empty($quote->created_at) ? substr((string) $quote->created_at, 0, 10) : '',
            'updatedDateIso' => !empty($quote->updated_at) ? substr((string) $quote->updated_at, 0, 10) : '',
            'picName' => (string) ($quote->pic_name ?? '-'),
            'clientName' => (string) ($quote->client_name ?? '-'),
            'clientAddressBlock' => $this->renderer->formatAddressBlock(
                $quote->client_address ?? null,
                $quote->client_city ?? null,
                $quote->client_state ?? null,
                $quote->client_zip ?? null
            ),
            'picEmail' => (string) ($quote->pic_email ?? '-'),
            'picPhone' => (string) ($quote->pic_phone ?? '-'),
            'serviceTitle' => (string) ($quote->service_title ?? ''),
            'serviceCode' => (string) ($quote->service_code ?? ''),
            'remarksHtml' => $remarksHtml,
            'items' => $items,
            'grossAmount' => $grossAmount,
            'discountAmount' => $discountAmount,
            'showSubtotal' => $showSubtotal,
            'subTotalNet' => $subTotalNet,
            'sstAmount' => $sstAmount,
            'sstPercentLabel' => $sstPercentLabel,
            'grandTotal' => $grandTotal,
            'preparedByName' => (string) ($quote->created_by_name ?? ''),
            'signOffTitle' => $signOffTitle,
            'appendProposalContent' => false,
            'proposalTitle' => trim($proposalTitle) !== '' ? trim($proposalTitle) : 'Service Proposal',
            'proposalContentHtml' => $proposalContentHtml,
            'logoDataUri' => $logoDataUri,
        ])->render();

        $dompdf = $this->renderer->renderPortraitWithFooter($html, $generatedAt, $generatorCode, $generatorId);
        $quotePdfBytes = $dompdf->output();

        if ($appendProposalContent) {
            $proposalHtml = view($this->renderer->pdfView('pdf.special-proposal', $quote->proposal_language ?? 'en'), [
                'proposal' => (object) [
                    'service_title' => $proposalServiceTitle !== '' ? $proposalServiceTitle : 'Service',
                    'proposal_language' => $quote->proposal_language ?? 'en',
                ],
                'proposalTitle' => trim($proposalTitle) !== '' ? trim($proposalTitle) : 'Service Proposal',
                'contentHtml' => $proposalContentHtml,
                'logoDataUri' => $logoDataUri,
            ])->render();
            $proposalPdf = $this->renderer->renderPortraitWithFooter($proposalHtml, $generatedAt, $generatorCode, $generatorId)->output();
            $proposalPdfAttachmentPaths = [$proposalPdf, ...$proposalPdfAttachmentPaths];
        }

        if (!empty($proposalPdfAttachmentPaths)) {
            $mergedBytes = $this->pdfMerge->mergeSequence([
                $quotePdfBytes,
                ...$proposalPdfAttachmentPaths,
            ]);
            if ($mergedBytes !== null) {
                $quotePdfBytes = $mergedBytes;
            }
        }

        $this->auditLog->log($request, "Generated Special quotation PDF for quote ID #{$quoteId}");

        $safeRefNo = preg_replace('/[^A-Za-z0-9]/', '_', (string) ($quote->quote_ref_no ?? "quote_{$quoteId}"));
        $safeClient = preg_replace('/[^A-Za-z0-9]/', '_', (string) ($quote->client_name ?? 'client'));
        $filename = $safeRefNo . '_' . trim($safeClient, '_') . '_Quotation.pdf';

        return response($quotePdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    private function specialAttachmentForeignKey(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
    }

    private function specialProposalTitle(string $serviceTitle): string
    {
        $serviceTitle = trim($serviceTitle);
        if ($serviceTitle === '') {
            return 'Service Proposal';
        }

        if (preg_match('/\bproposal$/i', $serviceTitle)) {
            return $serviceTitle;
        }

        if (preg_match('/\bservice$/i', $serviceTitle)) {
            return $serviceTitle . ' Proposal';
        }

        return $serviceTitle . ' Service Proposal';
    }

    private function specialAttachmentPathColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'stored_path') ? 'stored_path' : 'file_url';
    }

    private function specialAttachmentStoredPath(object $attachment): string
    {
        $pathCol = $this->specialAttachmentPathColumn();
        $primary = trim((string) ($attachment->{$pathCol} ?? ''));
        if ($primary !== '') {
            return $primary;
        }

        foreach (['stored_path', 'file_url'] as $fallbackCol) {
            if ($fallbackCol === $pathCol) {
                continue;
            }

            $fallback = trim((string) ($attachment->{$fallbackCol} ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    private function snapshotAttachmentPaths(object $snapshot): array
    {
        $attachments = json_decode((string) ($snapshot->attachments_json ?? ''), true);
        if (! is_array($attachments)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($attachment) => is_array($attachment) ? ($attachment['storedPath'] ?? null) : null,
            $attachments
        )));
    }

    private function resolveStoredPdfPath(string $storedPath): ?string
    {
        $relativePath = AppFilePaths::publicStorageRelativePath($storedPath);
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $absolutePath = AppFilePaths::storedPathLocalPath($relativePath);
        if ($absolutePath === null) {
            return null;
        }

        $resolved = realpath($absolutePath);
        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }

        return strtolower((string) pathinfo($resolved, PATHINFO_EXTENSION)) === 'pdf'
            ? $resolved
            : null;
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
