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

class SpecialProposalTemplateReadService
{
    private static bool $dompdfAutoloaderRegistered = false;
    private static array $columnExistsCache = [];

    public function indexSpecial(Request $request)
    {
        $singleId = (int) $request->query('id', 0);
        $legacy   = $this->isLegacyPhpRoute($request);

        $query = DB::table('proposal_template_special as sp')
            ->select(['sp.*'])
            ->where('sp.is_deleted', 0)
            ->orderByDesc('sp.created_at');

        if ($singleId <= 0 || $request->has('language') || $request->has('proposal_language')) {
            $this->applyProposalLanguageFilter($query, 'proposal_template_special', 'sp', $request);
        }

        if ($singleId > 0) {
            $query->where('sp.id', $singleId);
        }

        $paginator = $query->paginate(25);
        $rows      = $paginator->items();

        if (!empty($rows)) {
            $ids = array_map(fn ($r) => (int) $r->id, $rows);
            $attachmentFk = $this->specialAttachmentForeignKey();

            $attachmentRows = DB::table('proposal_special_attachments')
                ->whereIn($attachmentFk, $ids)
                ->orderBy($attachmentFk)
                ->orderBy('id')
                ->get();

            $historyRows = DB::table('proposal_template_special_history as h')
                ->leftJoin('staff_general as s', 's.staff_id', '=', 'h.created_by')
                ->whereIn('h.template_id', $ids)
                ->select([
                    'h.*',
                    DB::raw("COALESCE(s.name_code, CONCAT('Staff #', h.created_by)) as created_by_code"),
                ])
                ->orderByDesc('h.created_at')
                ->get();

            $attachmentsByTemplate = [];
            foreach ($attachmentRows as $att) {
                $attachmentsByTemplate[(int) $att->{$attachmentFk}][] = $this->normalizeSpecialAttachment($att);
            }

            $itemsByTemplate = $this->defaultLineItemsByTemplate($ids);

            $historyByTemplate = [];
            foreach ($historyRows as $h) {
                $historyByTemplate[$h->template_id][] = $h;
            }

            foreach ($rows as $row) {
                $row->attachments = $attachmentsByTemplate[$row->id] ?? [];
                $row->defaultLineItems = $itemsByTemplate[$row->id] ?? [];
                $row->history     = $historyByTemplate[$row->id]    ?? [];
            }
        }

        if ($singleId > 0) {
            if (empty($rows)) {
                return $this->errorResponse($legacy, 'Special proposal not found.', 404);
            }
            $record = $this->mapSpecialRecord($rows[0], $legacy);
            if ($legacy) {
                return response()->json([$record]);
            }
            return response()->json(['status' => 'success', 'data' => $record]);
        }

        $mapped = array_map(fn ($row) => $this->mapSpecialRecord($row, $legacy), $rows);
        if ($legacy) {
            return response()->json($mapped);
        }

        return response()->json([
            'status'     => 'success',
            'data'       => $mapped,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function listSpecial(Request $request)
    {
        $select = ['id', 'service_title', 'service_code'];
        foreach ([
            'proposal_language',
            'proposal_mode',
            'service_summary',
            'proposal_content',
            'content',
            'translation_status',
        ] as $column) {
            if ($this->hasColumn('proposal_template_special', $column)) {
                $select[] = $column;
            }
        }

        $query = DB::table('proposal_template_special')
            ->select($select)
            ->where('is_deleted', 0)
            ->orderBy('service_title');

        $language = $this->normalizeProposalLanguage($request->query('language', $request->query('proposal_language', 'en')));
        $this->applyProposalLanguageFilter($query, 'proposal_template_special', null, $request);
        $this->applyReviewedBmTemplateFilter($query, 'proposal_template_special', $language);
        $rows = $query->get();
        $ids = $rows->map(fn ($row) => (int) $row->id)->all();
        $attachmentsByTemplate = $this->attachmentsByTemplate($ids);
        $itemsByTemplate = $this->defaultLineItemsByTemplate($ids);

        $mapped = $rows->map(function ($row) use ($attachmentsByTemplate, $itemsByTemplate) {
            $attachments = $attachmentsByTemplate[(int) $row->id] ?? [];
            $proposalMode = $this->proposalMode($row, $attachments);
            $proposalContent = $this->proposalContent($row, $proposalMode);
            $appendability = $this->appendability($proposalMode, $proposalContent, $attachments);

            return [
                'id'          => (int) $row->id,
                'serviceTitle'=> $row->service_title,
                'serviceCode' => $row->service_code,
                'proposalLanguage' => $row->proposal_language ?? 'en',
                'proposalMode' => $proposalMode,
                'defaultLineItems' => $itemsByTemplate[(int) $row->id] ?? [],
                ...$appendability,
            ];
        })->values();

        if ($this->isLegacyPhpRoute($request)) {
            return response()->json($mapped);
        }

        return response()->json(['status' => 'success', 'data' => $mapped]);
    }

    private function applyProposalLanguageFilter($query, string $table, ?string $alias, Request $request): void
    {
        if (!$this->hasColumn($table, 'proposal_language')) {
            return;
        }

        $language = $this->normalizeProposalLanguage($request->query('language', $request->query('proposal_language', 'en')));
        $column = ($alias ? $alias . '.' : '') . 'proposal_language';
        $query->where($column, $language);
    }

    private function errorResponse(bool $legacy, string $message, int $status)
    {
        if ($legacy) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    private function isLegacyPhpRoute(Request $request): bool
    {
        return str_contains($request->path(), '.php');
    }

    private function mapSpecialRecord(object $row, bool $legacy): array
    {
        $history = array_map(function ($item) {
            return [
                'id'              => isset($item->id) ? (int) $item->id : null,
                'template_id'     => isset($item->template_id) ? (int) $item->template_id : null,
                'remarks'         => $item->remarks ?? null,
                'created_by'      => $item->created_by ?? null,
                'created_by_code' => $item->created_by_code ?? null,
                'created_at'      => $item->created_at ?? null,
            ];
        }, (array) ($row->history ?? []));

        $mapped = [
            'id'           => (int) $row->id,
            'serviceTitle' => $row->service_title ?? null,
            'serviceCode'  => $row->service_code ?? null,
            'content'      => $row->content ?? null,
            'proposalMode' => $this->proposalMode($row, (array) ($row->attachments ?? [])),
            'serviceSummary' => $row->service_summary ?? (($this->proposalMode($row, (array) ($row->attachments ?? [])) === 'upload') ? ($row->content ?? '') : ''),
            'proposalContent' => $row->proposal_content ?? (($this->proposalMode($row, (array) ($row->attachments ?? [])) === 'write') ? ($row->content ?? '') : ''),
            'dateCreated'  => $row->created_at ?? null,
            'proposalLanguage' => $row->proposal_language ?? 'en',
            'sourceTemplateId' => isset($row->source_template_id) ? (int) $row->source_template_id : null,
            'translationProvider' => $row->translation_provider ?? null,
            'translationStatus' => $row->translation_status ?? null,
            'translatedAt' => $row->translated_at ?? null,
            'translationNotes' => $row->translation_notes ?? null,
            'history'      => $history,
            'attachments'  => array_values((array) ($row->attachments ?? [])),
            'defaultLineItems' => array_values((array) ($row->defaultLineItems ?? [])),
        ];

        $mapped = [
            ...$mapped,
            ...$this->appendability(
                $mapped['proposalMode'],
                $mapped['proposalContent'],
                $mapped['attachments']
            ),
        ];

        if (!$legacy) {
            $mapped['created_at'] = $row->created_at ?? null;
            $mapped['updated_at'] = $row->updated_at ?? null;
        }

        return $mapped;
    }

    private function normalizeSpecialAttachment(object $att): array
    {
        $nameCol = $this->specialAttachmentNameColumn();
        $fileName = $att->{$nameCol} ?? '';
        $mimeType = $att->mime_type ?? null;

        return [
            'id'       => (int) $att->id,
            'fileName' => $fileName,
            'fileUrl'  => AppFilePaths::publicUrlForStoredPath($this->specialAttachmentStoredPath($att)),
            'mimeType' => $mimeType,
            'fileSize' => $att->file_size ?? null,
            'isPdf' => $this->isPdfAttachment($fileName, $mimeType),
        ];
    }

    private function paginationMeta(\Illuminate\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }

    private function specialAttachmentForeignKey(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
    }

    private function applyReviewedBmTemplateFilter($query, string $table, mixed $language): void
    {
        if (
            $this->normalizeProposalLanguage($language) !== 'ms-MY'
            || !$this->hasColumn($table, 'translation_status')
        ) {
            return;
        }

        $query->where(function ($statusQuery): void {
            $statusQuery
                ->whereNull('translation_status')
                ->orWhere('translation_status', '<>', 'machine_draft');
        });
    }
    private function normalizeProposalLanguage(mixed $language): string
    {
        return app(ProposalTemplateCrudSupport::class)->normalizeProposalLanguage($language);
    }
    private function hasColumn(string $table, string $column): bool
    {
        return app(ProposalTemplateCrudSupport::class)->hasColumn($table, $column);
    }

    private function specialAttachmentNameColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'original_filename') ? 'original_filename' : 'file_name';
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

    private function specialAttachmentPathColumn(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'stored_path') ? 'stored_path' : 'file_url';
    }

    private function attachmentsByTemplate(array $ids): array
    {
        if (empty($ids) || ! Schema::hasTable('proposal_special_attachments')) {
            return [];
        }

        $attachmentFk = $this->specialAttachmentForeignKey();
        $rows = DB::table('proposal_special_attachments')
            ->whereIn($attachmentFk, $ids)
            ->orderBy($attachmentFk)
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->{$attachmentFk}][] = $this->normalizeSpecialAttachment($row);
        }

        return $grouped;
    }

    private function defaultLineItemsByTemplate(array $ids): array
    {
        if (empty($ids) || ! Schema::hasTable('proposal_template_special_items')) {
            return [];
        }

        $rows = DB::table('proposal_template_special_items')
            ->whereIn('template_id', $ids)
            ->orderBy('template_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->template_id][] = [
                'id' => (int) $row->id,
                'title' => $row->line_item_title ?? '',
                'description' => $row->description ?? '',
                'unit' => $row->unit ?? '',
                'quantity' => (float) ($row->default_quantity ?? 1),
                'unitPrice' => (float) ($row->default_unit_price ?? 0),
                'amount' => (float) ($row->default_line_total ?? 0),
                'sortOrder' => (int) ($row->sort_order ?? 0),
            ];
        }

        return $grouped;
    }

    private function proposalMode(object $row, array $attachments): string
    {
        $mode = $row->proposal_mode ?? null;
        if (in_array($mode, ['upload', 'write'], true)) {
            return $mode;
        }

        return count($attachments) > 0 ? 'upload' : 'write';
    }

    private function proposalContent(object $row, string $proposalMode): string
    {
        $value = $row->proposal_content ?? null;
        if ($value !== null) {
            return (string) $value;
        }

        return $proposalMode === 'write' ? (string) ($row->content ?? '') : '';
    }

    private function appendability(string $proposalMode, string $proposalContent, array $attachments): array
    {
        $pdfCount = count(array_filter($attachments, fn ($attachment): bool => (bool) ($attachment['isPdf'] ?? false)));
        $hasWrittenContent = trim(strip_tags($proposalContent)) !== '';
        $hasAppendable = $proposalMode === 'upload' ? $pdfCount > 0 : $hasWrittenContent;

        return [
            'hasAppendableProposal' => $hasAppendable,
            'appendablePdfCount' => $pdfCount,
            'hasWrittenProposalContent' => $hasWrittenContent,
            'appendableProposalMessage' => $hasAppendable
                ? ($proposalMode === 'upload'
                    ? "Upload proposal: {$pdfCount} PDF attachment" . ($pdfCount === 1 ? '' : 's')
                    : 'Written proposal: content will be appended')
                : 'No appendable proposal content',
        ];
    }

    private function isPdfAttachment(?string $fileName, ?string $mimeType): bool
    {
        return strtolower((string) $mimeType) === 'application/pdf'
            || strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION)) === 'pdf';
    }
}
