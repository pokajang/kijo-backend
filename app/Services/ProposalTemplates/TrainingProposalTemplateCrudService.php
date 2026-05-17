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

class TrainingProposalTemplateCrudService
{
    private static bool $dompdfAutoloaderRegistered = false;
    private static array $columnExistsCache = [];

    public function __construct(
        private AuditLogService $auditLog,
    ) {}

    public function indexTraining(Request $request)
    {
        $singleId = (int) $request->query('id', 0);
        $legacy   = $this->isLegacyPhpRoute($request);

        $query = DB::table('proposal_template_training_main as t')
            ->select(['t.*'])
            ->where('t.is_deleted', 0)
            ->orderByDesc('t.created_at');

        if ($singleId <= 0 || $request->has('language') || $request->has('proposal_language')) {
            $this->applyProposalLanguageFilter($query, 'proposal_template_training_main', 't', $request);
        }

        if ($singleId > 0) {
            $query->where('t.id', $singleId);
        }

        $paginator = $query->paginate(25);
        $rows      = $paginator->items();

        if (!empty($rows)) {
            $ids = array_map(fn ($r) => (int) $r->id, $rows);

            $agendaRows = DB::table('proposal_template_training_agenda')
                ->whereIn('template_id', $ids)
                ->orderBy('template_id')
                ->orderBy('day')
                ->orderBy('start_time')
                ->get();

            $historyRows = DB::table('proposal_template_training_history as h')
                ->leftJoin('staff_general as s', 's.staff_id', '=', 'h.created_by')
                ->whereIn('h.template_id', $ids)
                ->select([
                    'h.*',
                    DB::raw("COALESCE(s.name_code, CONCAT('Staff #', h.created_by)) as created_by_code"),
                ])
                ->orderBy('h.template_id')
                ->orderByDesc('h.created_at')
                ->get();

            $agendaByTemplate  = [];
            foreach ($agendaRows as $ag) {
                $agendaByTemplate[$ag->template_id][] = $ag;
            }

            $historyByTemplate = [];
            foreach ($historyRows as $h) {
                $historyByTemplate[$h->template_id][] = $h;
            }

            foreach ($rows as $row) {
                $row->agenda  = $agendaByTemplate[$row->id]  ?? [];
                $row->history = $historyByTemplate[$row->id] ?? [];
            }
        }

        if ($singleId > 0) {
            if (empty($rows)) {
                return $this->errorResponse($legacy, 'Training proposal not found.', 404);
            }
            $record = $this->mapTrainingRecord($rows[0], $legacy);
            if ($legacy) {
                return response()->json([$record]);
            }
            return response()->json(['status' => 'success', 'data' => $record]);
        }

        $mapped = array_map(fn ($row) => $this->mapTrainingRecord($row, $legacy), $rows);
        if ($legacy) {
            return response()->json($mapped);
        }

        return response()->json([
            'status'     => 'success',
            'data'       => $mapped,
            'pagination' => $this->paginationMeta($paginator),
        ]);
    }

    public function storeTraining(StoreTrainingProposalRequest $request)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);

        try {
            DB::beginTransaction();

            $mainInsert = [
                'training_title'                  => $data['trainingTitle'],
                'training_code'                   => $data['trainingCode'],
                'hrd_no'                          => $data['hrdNo'] ?? null,
                'introduction'                    => $data['introduction'],
                'objectives'                      => $data['objectives'],
                'modules'                         => $data['modules'] ?? null,
                'training_requirements'           => $data['trainingRequirements'] ?? null,
                'training_materials'              => $data['trainingMaterials'] ?? null,
                'lecture_medium'                  => $data['lectureMedium'] ?? null,
                'duration'                        => $data['duration'],
                'method_theory'                   => isset($data['method_theory']) ? (int) $data['method_theory'] : 0,
                'method_theory_desc'              => $data['method_theory_desc'] ?? null,
                'method_practical'                => isset($data['method_practical']) ? (int) $data['method_practical'] : 0,
                'method_practical_desc'           => $data['method_practical_desc'] ?? null,
                'created_by'                      => $staffId,
                'is_deleted'                      => 0,
                'created_at'                      => now(),
            ];
            $additionalCol = $this->hasColumn('proposal_template_training_main', 'additional_training_requirements')
                ? 'additional_training_requirements'
                : 'additional_requirements';
            $mainInsert[$additionalCol] = $data['additionalTrainingRequirements'] ?? null;

            $id = DB::table('proposal_template_training_main')
                ->insertGetId($this->filterExistingColumns('proposal_template_training_main', $mainInsert));

            if (!empty($data['agenda'])) {
                $agendaTopicCol = $this->hasColumn('proposal_template_training_agenda', 'topic') ? 'topic' : 'activity';
                $agendaRows = array_map(fn (array $ag) => [
                    'template_id' => $id,
                    'day'         => $ag['day'],
                    'start_time'  => $ag['start_time'],
                    'end_time'    => $ag['end_time'],
                    $agendaTopicCol => $ag['topic'],
                ], $data['agenda']);

                DB::table('proposal_template_training_agenda')->insert($agendaRows);
            }

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? 'Proposal first created - ' . trim((string) $data['remarks'])
                : null;
            $this->insertTemplateHistory('proposal_template_training_history', $id, $remarks, $staffId, 'Created');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to create training proposal.', 500);
        }

        $this->auditLog->log($request, "Created training proposal template #{$id} \"{$data['trainingTitle']}\" by {$staffCode}");

        if ($legacy) {
            return response()->json([
                'success' => true,
                'message' => 'Training proposal created successfully.',
                'id'      => $id,
            ], 201);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'Training proposal created successfully.',
            'id'      => $id,
        ], 201);
    }

    public function updateTraining(UpdateTrainingProposalRequest $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $data      = $request->validated();
        $legacy    = $this->isLegacyPhpRoute($request);

        $row = DB::table('proposal_template_training_main')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Training proposal not found.', 404);
        }

        try {
            DB::beginTransaction();

            $mainUpdate = [
                'training_title'                  => $data['trainingTitle'],
                'training_code'                   => $data['trainingCode'],
                'hrd_no'                          => $data['hrdNo'] ?? null,
                'introduction'                    => $data['introduction'],
                'objectives'                      => $data['objectives'],
                'modules'                         => $data['modules'] ?? null,
                'training_requirements'           => $data['trainingRequirements'] ?? null,
                'training_materials'              => $data['trainingMaterials'] ?? null,
                'lecture_medium'                  => $data['lectureMedium'] ?? null,
                'duration'                        => $data['duration'],
                'method_theory'                   => isset($data['method_theory']) ? (int) $data['method_theory'] : 0,
                'method_theory_desc'              => $data['method_theory_desc'] ?? null,
                'method_practical'                => isset($data['method_practical']) ? (int) $data['method_practical'] : 0,
                'method_practical_desc'           => $data['method_practical_desc'] ?? null,
                'updated_at'                      => now(),
            ];
            $additionalCol = $this->hasColumn('proposal_template_training_main', 'additional_training_requirements')
                ? 'additional_training_requirements'
                : 'additional_requirements';
            $mainUpdate[$additionalCol] = $data['additionalTrainingRequirements'] ?? null;
            $this->markReviewedBmDraftOnUpdate('proposal_template_training_main', $row, $mainUpdate);

            DB::table('proposal_template_training_main')
                ->where('id', $id)
                ->update($this->filterExistingColumns('proposal_template_training_main', $mainUpdate));

            DB::table('proposal_template_training_agenda')->where('template_id', $id)->delete();

            if (!empty($data['agenda'])) {
                $agendaTopicCol = $this->hasColumn('proposal_template_training_agenda', 'topic') ? 'topic' : 'activity';
                $agendaRows = array_map(fn (array $ag) => [
                    'template_id' => $id,
                    'day'         => $ag['day'],
                    'start_time'  => $ag['start_time'],
                    'end_time'    => $ag['end_time'],
                    $agendaTopicCol => $ag['topic'],
                ], $data['agenda']);

                DB::table('proposal_template_training_agenda')->insert($agendaRows);
            }

            $remarks = isset($data['remarks']) && trim((string) $data['remarks']) !== ''
                ? trim((string) $data['remarks'])
                : null;

            $this->insertTemplateHistory('proposal_template_training_history', $id, $remarks, $staffId, 'Updated');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to update training proposal.', 500);
        }

        $this->auditLog->log($request, "Updated training proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Training proposal updated successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Training proposal updated successfully.']);
    }

    public function destroyTraining(Request $request, int $id)
    {
        $staffId   = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $legacy    = $this->isLegacyPhpRoute($request);

        $row = DB::table('proposal_template_training_main')->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Training proposal not found.', 404);
        }

        $inUseQuote = DB::table('quotes_training')
            ->select('id', 'quote_ref_no', 'status')
            ->where('proposal_id', $id)
            ->where('attach_proposal', 1)
            ->first();

        if ($inUseQuote) {
            return $this->errorResponse($legacy, $this->templateInUseMessage($inUseQuote), 409);
        }

        try {
            DB::beginTransaction();

            DB::table('proposal_template_training_main')->where('id', $id)->update($this->filterExistingColumns('proposal_template_training_main', [
                'is_deleted' => 1,
                'deleted_at' => now(),
                'deleted_by' => $staffId > 0 ? $staffId : null,
            ]));

            $this->insertTemplateHistory('proposal_template_training_history', $id, null, $staffId, 'Deleted');

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return $this->errorResponse($legacy, 'Failed to delete training proposal.', 500);
        }

        $this->auditLog->log($request, "Deleted training proposal template #{$id} by {$staffCode}");

        if ($legacy) {
            return response()->json(['success' => true, 'message' => 'Training proposal deleted successfully.']);
        }

        return response()->json(['status' => 'success', 'message' => 'Training proposal deleted successfully.']);
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

    private function mapTrainingRecord(object $row, bool $legacy): array
    {
        $agenda = array_map(function ($item) {
            return [
                'id'         => isset($item->id) ? (int) $item->id : null,
                'day'        => isset($item->day) ? (int) $item->day : null,
                'start_time' => $item->start_time ?? null,
                'end_time'   => $item->end_time ?? null,
                'topic'      => $item->topic ?? ($item->activity ?? null),
            ];
        }, (array) ($row->agenda ?? []));

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
            'id'                             => (int) $row->id,
            'trainingTitle'                  => $row->training_title ?? null,
            'trainingCode'                   => $row->training_code ?? null,
            'hrdNo'                          => $row->hrd_no ?? null,
            'introduction'                   => $row->introduction ?? null,
            'objectives'                     => $row->objectives ?? null,
            'modules'                        => $row->modules ?? null,
            'trainingRequirements'           => $row->training_requirements ?? null,
            'additionalRequirements'         => $row->additional_requirements ?? ($row->additional_training_requirements ?? null),
            'additionalTrainingRequirements' => $row->additional_training_requirements ?? ($row->additional_requirements ?? null),
            'trainingMaterials'              => $row->training_materials ?? null,
            'lectureMedium'                  => $row->lecture_medium ?? null,
            'duration'                       => $row->duration ?? null,
            'methodTheory'                   => isset($row->method_theory) ? (int) $row->method_theory : 0,
            'methodTheoryDesc'               => $row->method_theory_desc ?? null,
            'methodPractical'                => isset($row->method_practical) ? (int) $row->method_practical : 0,
            'methodPracticalDesc'            => $row->method_practical_desc ?? null,
            'dateCreated'                    => $row->created_at ?? null,
            'proposalLanguage'               => $row->proposal_language ?? 'en',
            'sourceTemplateId'                => isset($row->source_template_id) ? (int) $row->source_template_id : null,
            'translationProvider'            => $row->translation_provider ?? null,
            'translationStatus'              => $row->translation_status ?? null,
            'translatedAt'                   => $row->translated_at ?? null,
            'translationNotes'               => $row->translation_notes ?? null,
            'agenda'                         => $agenda,
            'history'                        => $history,
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
    private function hasColumn(string $table, string $column): bool
    {
        return app(ProposalTemplateCrudSupport::class)->hasColumn($table, $column);
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
    private function normalizeProposalLanguage(mixed $language): string
    {
        return app(ProposalTemplateCrudSupport::class)->normalizeProposalLanguage($language);
    }

}
