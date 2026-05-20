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

class ManpowerProposalTemplateCrudService
{
    private static bool $dompdfAutoloaderRegistered = false;
    private static array $columnExistsCache = [];

    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function indexManpower(Request $request)
    {
        $singleId = (int) $request->query('id', 0);
        $legacy   = $this->isLegacyPhpRoute($request);

        $query = DB::table('proposal_template_manpower as m')
            ->select(['m.*'])
            ->where('m.is_deleted', 0)
            ->orderByDesc('m.created_at');

        if ($singleId <= 0 || $request->has('language') || $request->has('proposal_language')) {
            $this->applyProposalLanguageFilter($query, 'proposal_template_manpower', 'm', $request);
        }

        if ($singleId > 0) {
            $query->where('m.id', $singleId);
        }

        $paginator = $query->paginate(25);
        $rows      = $paginator->items();

        if (!empty($rows)) {
            $ids = array_map(fn ($r) => (int) $r->id, $rows);

            $historyRows = DB::table('proposal_template_manpower_history as h')
                ->leftJoin('staff_general as s', 's.staff_id', '=', 'h.created_by')
                ->whereIn('h.template_id', $ids)
                ->select([
                    'h.*',
                    DB::raw("COALESCE(s.name_code, CONCAT('Staff #', h.created_by)) as created_by_code"),
                ])
                ->orderByDesc('h.created_at')
                ->get();

            $historyByTemplate = [];
            foreach ($historyRows as $h) {
                $historyByTemplate[$h->template_id][] = $h;
            }

            foreach ($rows as $row) {
                $row->history = $historyByTemplate[$row->id] ?? [];
            }
        }

        if ($singleId > 0) {
            if (empty($rows)) {
                return $this->errorResponse($legacy, 'Manpower proposal not found.', 404);
            }
            $record = $this->mapManpowerRecord($rows[0], $legacy);
            if ($legacy) {
                return response()->json([$record]);
            }
            return response()->json(['status' => 'success', 'data' => $record]);
        }

        $mapped = array_map(fn ($row) => $this->mapManpowerRecord($row, $legacy), $rows);
        if ($legacy) {
            return response()->json($mapped);
        }

        return response()->json([
            'status'     => 'success',
            'data'       => $mapped,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function storeManpower(StoreManpowerProposalRequest $request)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);

        try {
            DB::beginTransaction();

            $id = DB::table('proposal_template_manpower')->insertGetId($this->filterExistingColumns('proposal_template_manpower', [
                'service_title'                  => $data['serviceTitle'],
                'service_code'                   => $data['serviceCode'],
                'introduction'                   => $data['introduction'],
                'service_deliverables'           => $data['serviceDeliverables'],
                'supplied_manpower_deliverables' => $data['suppliedManpowerDeliverables'] ?? null,
                'custom_section'                 => $data['customSection'] ?? null,
                'created_by'                     => $staffId,
                'is_deleted'                     => 0,
                'created_at'                     => now(),
            ]));

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? 'Proposal first created - ' . trim((string) $data['remarks'])
                : null;
            $this->insertTemplateHistory('proposal_template_manpower_history', $id, $remarks, $staffId, 'Created');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to create manpower proposal.', 500);
        }

        $this->auditLog->log($request, "Created manpower proposal template #{$id} \"{$data['serviceTitle']}\" by {$staffCode}");

        if ($legacy) {
            return response()->json([
                'success' => true,
                'message' => 'Manpower proposal created successfully.',
                'id'      => $id,
            ], 201);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Manpower proposal created successfully.',
            'id'      => $id,
        ], 201);
    }

    public function updateManpower(UpdateManpowerProposalRequest $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);

        $row = DB::table('proposal_template_manpower')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Manpower proposal not found.', 404);
        }

        try {
            DB::beginTransaction();

            $update = [
                'service_title'                  => $data['serviceTitle'],
                'service_code'                   => $data['serviceCode'],
                'introduction'                   => $data['introduction'],
                'service_deliverables'           => $data['serviceDeliverables'],
                'supplied_manpower_deliverables' => $data['suppliedManpowerDeliverables'] ?? null,
                'custom_section'                 => $data['customSection'] ?? null,
                'updated_at'                     => now(),
            ];
            $this->markReviewedBmDraftOnUpdate('proposal_template_manpower', $row, $update);

            DB::table('proposal_template_manpower')->where('id', $id)->update($this->filterExistingColumns('proposal_template_manpower', $update));

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? trim((string) $data['remarks'])
                : null;

            $this->insertTemplateHistory('proposal_template_manpower_history', $id, $remarks, $staffId, 'Updated');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to update manpower proposal.', 500);
        }

        $this->auditLog->log($request, "Updated manpower proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Manpower proposal updated successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Manpower proposal updated successfully.']);
    }

    public function destroyManpower(Request $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $legacy    = $this->isLegacyPhpRoute($request);

        $row = DB::table('proposal_template_manpower')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Manpower proposal not found.', 404);
        }

        $inUseQuote = DB::table('quotes_manpower')
            ->select('id', 'quote_ref_no', 'status')
            ->where('mp_id', $id)
            ->where('attach_proposal', 1)
            ->first();

        if ($inUseQuote) {
            return $this->errorResponse($legacy, $this->templateInUseMessage($inUseQuote), 409);
        }

        try {
            DB::beginTransaction();

            DB::table('proposal_template_manpower')->where('id', $id)->update($this->filterExistingColumns('proposal_template_manpower', [
                'is_deleted' => 1,
                'deleted_at' => now(),
                'deleted_by' => $staffId > 0 ? $staffId : null,
            ]));

            $this->insertTemplateHistory('proposal_template_manpower_history', $id, null, $staffId, 'Deleted');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to delete manpower proposal.', 500);
        }

        $this->auditLog->log($request, "Deleted manpower proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Manpower proposal deleted successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Manpower proposal deleted successfully.']);
    }

    public function listManpower(Request $request)
    {
        $select = ['id', 'service_title', 'service_code'];
        if ($this->hasColumn('proposal_template_manpower', 'proposal_language')) {
            $select[] = 'proposal_language';
        }

        $query = DB::table('proposal_template_manpower')
            ->select($select)
            ->where('is_deleted', 0)
            ->orderBy('service_title');

        $language = $this->normalizeProposalLanguage($request->query('language', $request->query('proposal_language', 'en')));
        $this->applyProposalLanguageFilter($query, 'proposal_template_manpower', null, $request);
        $this->applyReviewedBmTemplateFilter($query, 'proposal_template_manpower', $language);
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

    private function mapManpowerRecord(object $row, bool $legacy): array
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
            'id'                          => (int) $row->id,
            'serviceTitle'                => $row->service_title ?? null,
            'serviceCode'                 => $row->service_code ?? null,
            'introduction'                => $row->introduction ?? null,
            'serviceDeliverables'         => $row->service_deliverables ?? null,
            'suppliedManpowerDeliverables'=> $row->supplied_manpower_deliverables ?? null,
            'customSection'               => $row->custom_section ?? null,
            'dateCreated'                 => $row->created_at ?? null,
            'proposalLanguage'            => $row->proposal_language ?? 'en',
            'sourceTemplateId'             => isset($row->source_template_id) ? (int) $row->source_template_id : null,
            'translationProvider'         => $row->translation_provider ?? null,
            'translationStatus'           => $row->translation_status ?? null,
            'translatedAt'                => $row->translated_at ?? null,
            'translationNotes'            => $row->translation_notes ?? null,
            'history'                     => $history,
        ];

        if (!$legacy) {
            $mapped['created_at'] = $row->created_at ?? null;
            $mapped['updated_at'] = $row->updated_at ?? null;
        }

        return $mapped;
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
    private function filterExistingColumns(string $table, array $payload): array
    {
        return app(ProposalTemplateCrudSupport::class)->filterExistingColumns($table, $payload);
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

    private function templateInUseMessage(object $quote): string
    {
        $quoteRef = trim((string) ($quote->quote_ref_no ?? ''));
        $quoteLabel = $quoteRef !== '' ? $quoteRef : ('#' . (string) ($quote->id ?? ''));
        $status = trim((string) ($quote->status ?? ''));
        $statusText = $status !== '' ? " ({$status})" : '';

        return "Cannot delete: this proposal template is attached to quote {$quoteLabel}{$statusText}.";
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

}
