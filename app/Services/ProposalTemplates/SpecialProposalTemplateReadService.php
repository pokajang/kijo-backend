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

            $historyByTemplate = [];
            foreach ($historyRows as $h) {
                $historyByTemplate[$h->template_id][] = $h;
            }

            foreach ($rows as $row) {
                $row->attachments = $attachmentsByTemplate[$row->id] ?? [];
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
        $query = DB::table('proposal_template_special')
            ->select(['id', 'service_title', 'service_code', 'proposal_language'])
            ->where('is_deleted', 0)
            ->orderBy('service_title');

        $language = $this->normalizeProposalLanguage($request->query('language', $request->query('proposal_language', 'en')));
        $this->applyProposalLanguageFilter($query, 'proposal_template_special', null, $request);
        $this->applyReviewedBmTemplateFilter($query, 'proposal_template_special', $language);
        $rows = $query->get();

        $mapped = $rows->map(fn ($row) => [
            'id'          => (int) $row->id,
            'serviceTitle'=> $row->service_title,
            'serviceCode' => $row->service_code,
            'proposalLanguage' => $row->proposal_language ?? 'en',
        ])->values();

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
            'dateCreated'  => $row->created_at ?? null,
            'proposalLanguage' => $row->proposal_language ?? 'en',
            'sourceTemplateId' => isset($row->source_template_id) ? (int) $row->source_template_id : null,
            'translationProvider' => $row->translation_provider ?? null,
            'translationStatus' => $row->translation_status ?? null,
            'translatedAt' => $row->translated_at ?? null,
            'translationNotes' => $row->translation_notes ?? null,
            'history'      => $history,
            'attachments'  => array_values((array) ($row->attachments ?? [])),
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

        return [
            'id'       => (int) $att->id,
            'fileName' => $att->{$nameCol} ?? '',
            'fileUrl'  => AppFilePaths::publicUrlForStoredPath($this->specialAttachmentStoredPath($att)),
            'mimeType' => $att->mime_type ?? null,
            'fileSize' => $att->file_size ?? null,
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
}
