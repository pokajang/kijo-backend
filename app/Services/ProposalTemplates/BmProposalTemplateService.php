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
use App\Services\Translation\TranslationService;
use App\Support\AppFilePaths;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BmProposalTemplateService
{
    private static bool $dompdfAutoloaderRegistered = false;
    private static array $columnExistsCache = [];

    public function __construct(
        private AuditLogService $auditLog,
        private TranslationService $translationService,
    ) {}

    public function createBmCopy(Request $request, string $type, int $id)
    {
        $type = strtolower(trim($type));
        $legacy = $this->isLegacyPhpRoute($request);
        $staffId = (int) $request->session()->get('staff_id', 0);
        $staffCode = trim((string) $request->session()->get('name_code', ''));
        $config = $this->bmCopyConfig($type);

        if (!$config) {
            return $this->errorResponse($legacy, 'Unsupported proposal template type.', 404);
        }

        $table = $config['table'];
        $row = DB::table($table)->where('id', $id)->where('is_deleted', 0)->first();
        if (!$row) {
            return $this->errorResponse($legacy, 'Proposal template not found.', 404);
        }

        if ($this->normalizeProposalLanguage($row->proposal_language ?? 'en') !== 'en') {
            return $this->errorResponse($legacy, 'BM copy can only be created from an English template.', 422);
        }

        $lockName = "proposal_bm_copy:{$table}:{$id}";
        $lockAcquired = false;
        $copiedSpecialAttachmentPaths = [];
        $newId = null;

        try {
            $lockAcquired = $this->acquireBmCopyLock($lockName);
            if (!$lockAcquired) {
                return $this->errorResponse($legacy, 'Another BM copy is being created for this template. Please try again.', 409);
            }

            if ($this->hasColumn($table, 'source_template_id')) {
                $existing = $this->findExistingBmCopy($table, $id);
                if ($existing) {
                    return $this->existingBmCopyResponse($legacy, $existing);
                }
            }

            DB::beginTransaction();

            $payload = (array) $row;
            unset($payload['id']);
            foreach (['created_at', 'updated_at', 'deleted_at', 'deleted_by'] as $column) {
                unset($payload[$column]);
            }

            foreach ($config['text_fields'] as $field) {
                if (array_key_exists($field, $payload) && trim((string) ($payload[$field] ?? '')) !== '') {
                    $payload[$field] = $this->translationService->translateText((string) $payload[$field], 'ms', 'en');
                }
            }

            foreach ($config['html_fields'] as $field) {
                if (array_key_exists($field, $payload) && trim((string) ($payload[$field] ?? '')) !== '') {
                    $payload[$field] = $this->translationService->translateHtml((string) $payload[$field], 'ms', 'en');
                }
            }

            $payload['proposal_language'] = 'ms-MY';
            $payload['source_template_id'] = $id;
            $payload['translation_provider'] = 'google';
            $payload['translation_status'] = 'machine_draft';
            $payload['translated_at'] = now();
            $payload['translation_notes'] = $type === 'special'
                ? 'Machine translated BM draft. Uploaded attachments require manual BM review/replacement.'
                : 'Machine translated BM draft. Please review before customer use.';
            $payload['is_deleted'] = 0;
            $payload['created_by'] = $staffId > 0 ? $staffId : ($row->created_by ?? null);
            $payload['created_at'] = now();

            $newId = (int) DB::table($table)->insertGetId($this->filterExistingColumns($table, $payload));

            if ($type === 'training') {
                $this->copyTrainingAgendaToBm($id, $newId);
            }

            if ($type === 'special') {
                $this->copySpecialAttachmentsToBm($id, $newId, $copiedSpecialAttachmentPaths);
            }

            $this->insertTemplateHistory($config['history_table'], $newId, 'BM copy created from template #' . $id . ' using Google Translate.', $staffId, 'Created');

            DB::commit();
        } catch (QueryException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->deleteCopiedSpecialAttachmentPaths($copiedSpecialAttachmentPaths);
            report($e);

            $existing = $this->hasColumn($table, 'source_template_id')
                ? $this->findExistingBmCopy($table, $id)
                : null;
            if ($existing && $this->isDuplicateKeyException($e)) {
                return $this->existingBmCopyResponse($legacy, $existing);
            }

            return $this->errorResponse(
                $legacy,
                'Failed to create BM copy. Translation completed, but saving the BM proposal failed.',
                500
            );
        } catch (TranslationException $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->deleteCopiedSpecialAttachmentPaths($copiedSpecialAttachmentPaths);
            report($e);

            return $this->errorResponse($legacy, 'Failed to create BM copy. ' . $e->getMessage(), 502);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->deleteCopiedSpecialAttachmentPaths($copiedSpecialAttachmentPaths);
            report($e);
            return $this->errorResponse($legacy, 'Failed to create BM copy. ' . $e->getMessage(), 500);
        } finally {
            if ($lockAcquired) {
                $this->releaseBmCopyLock($lockName);
            }
        }

        $this->auditLog->log($request, "Created BM {$type} proposal template #{$newId} from #{$id} by {$staffCode}");

        return response()->json([
            'status' => 'success',
            'message' => 'BM copy created successfully.',
            'id' => $newId,
            'bmTemplateId' => $newId,
            'data' => [
                'id' => $newId,
                'bmTemplateId' => $newId,
                'sourceTemplateId' => $id,
                'proposalLanguage' => 'ms-MY',
            ],
        ], 201);
    }

    private function acquireBmCopyLock(string $lockName): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        $lockResult = DB::selectOne('SELECT GET_LOCK(?, 10) as acquired', [$lockName]);
        return (int) ($lockResult->acquired ?? 0) === 1;
    }

    private function bmCopyConfig(string $type): ?array
    {
        return match ($type) {
            'training' => [
                'table' => 'proposal_template_training_main',
                'history_table' => 'proposal_template_training_history',
                'text_fields' => ['training_title'],
                'html_fields' => [
                    'introduction',
                    'objectives',
                    'modules',
                    'training_requirements',
                    'additional_requirements',
                    'additional_training_requirements',
                    'training_materials',
                    'lecture_medium',
                    'method_theory_desc',
                    'method_practical_desc',
                ],
            ],
            'ih' => [
                'table' => 'proposal_template_ih',
                'history_table' => 'proposal_template_ih_history',
                'text_fields' => ['service_title'],
                'html_fields' => ['introduction', 'objectives', 'work_scope', 'schedule', 'reference', 'other_fields'],
            ],
            'manpower' => [
                'table' => 'proposal_template_manpower',
                'history_table' => 'proposal_template_manpower_history',
                'text_fields' => ['service_title'],
                'html_fields' => ['introduction', 'service_deliverables', 'supplied_manpower_deliverables', 'custom_section'],
            ],
            'special' => [
                'table' => 'proposal_template_special',
                'history_table' => 'proposal_template_special_history',
                'text_fields' => ['service_title'],
                'html_fields' => ['content'],
            ],
            default => null,
        };
    }

    private function copySpecialAttachmentsToBm(int $sourceId, int $targetId, array &$copiedPaths): void
    {
        $attachmentFk = $this->specialAttachmentForeignKey();
        $attachments = DB::table('proposal_special_attachments')
            ->where($attachmentFk, $sourceId)
            ->orderBy('id')
            ->get();

        foreach ($attachments as $attachment) {
            $sourcePath = $this->specialAttachmentStoredPath($attachment);
            $relativeSourcePath = AppFilePaths::publicStorageRelativePath($sourcePath);
            if ($relativeSourcePath === null || ! AppFilePaths::storedPathExists($relativeSourcePath)) {
                continue;
            }

            $ext = pathinfo($relativeSourcePath, PATHINFO_EXTENSION);
            $targetPath = 'proposal-templates/special/' . $targetId . '/' . uniqid('sp_att_bm_', true) . ($ext ? '.' . $ext : '');
            $copied = AppFilePaths::copyStoredPath($relativeSourcePath, $targetPath);
            if (! $copied || ! AppFilePaths::storedPathExists($targetPath)) {
                throw new RuntimeException('Failed to copy special proposal attachment for the BM template.');
            }
            $copiedPaths[] = $targetPath;

            $this->insertSpecialAttachment(
                $targetId,
                (string) ($attachment->{$this->specialAttachmentNameColumn()} ?? basename($relativeSourcePath)),
                $targetPath,
                $attachment->mime_type ?? null,
                isset($attachment->file_size) ? (int) $attachment->file_size : null,
            );
        }
    }

    private function copyTrainingAgendaToBm(int $sourceId, int $targetId): void
    {
        $agendaRows = DB::table('proposal_template_training_agenda')
            ->where('template_id', $sourceId)
            ->orderBy('day')
            ->orderBy('start_time')
            ->get();

        if ($agendaRows->isEmpty()) {
            return;
        }

        $topicCol = $this->hasColumn('proposal_template_training_agenda', 'topic') ? 'topic' : 'activity';
        $rows = [];
        foreach ($agendaRows as $agenda) {
            $topic = (string) ($agenda->{$topicCol} ?? '');
            $rows[] = $this->filterExistingColumns('proposal_template_training_agenda', [
                'template_id' => $targetId,
                'day' => $agenda->day ?? 1,
                'start_time' => $agenda->start_time ?? null,
                'end_time' => $agenda->end_time ?? null,
                $topicCol => trim($topic) !== ''
                    ? $this->translationService->translateHtml($topic, 'ms', 'en')
                    : $topic,
            ]);
        }

        DB::table('proposal_template_training_agenda')->insert($rows);
    }

    private function deleteCopiedSpecialAttachmentPaths(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                AppFilePaths::deleteStoredPath($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function errorResponse(bool $legacy, string $message, int $status)
    {
        if ($legacy) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }

    private function existingBmCopyResponse(bool $legacy, object $existing)
    {
        $bmTemplateId = (int) $existing->id;
        $sourceTemplateId = isset($existing->source_template_id) ? (int) $existing->source_template_id : null;

        return response()->json([
            'status' => 'success',
            'message' => 'BM copy already exists.',
            'id' => $bmTemplateId,
            'bmTemplateId' => $bmTemplateId,
            'data' => [
                'id' => $bmTemplateId,
                'bmTemplateId' => $bmTemplateId,
                'sourceTemplateId' => $sourceTemplateId,
                'proposalLanguage' => 'ms-MY',
            ],
        ]);
    }
    private function filterExistingColumns(string $table, array $payload): array
    {
        return app(ProposalTemplateCrudSupport::class)->filterExistingColumns($table, $payload);
    }

    private function findExistingBmCopy(string $table, int $sourceTemplateId): ?object
    {
        if (!$this->hasColumn($table, 'source_template_id')) {
            return null;
        }

        return DB::table($table)
            ->where('source_template_id', $sourceTemplateId)
            ->where('proposal_language', 'ms-MY')
            ->where('is_deleted', 0)
            ->orderByDesc('id')
            ->first(['id', 'is_deleted', 'source_template_id']);
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

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return $sqlState === '23000' || $driverCode === '1062';
    }

    private function isLegacyPhpRoute(Request $request): bool
    {
        return str_contains($request->path(), '.php');
    }
    private function normalizeProposalLanguage(mixed $language): string
    {
        return app(ProposalTemplateCrudSupport::class)->normalizeProposalLanguage($language);
    }

    private function releaseBmCopyLock(string $lockName): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        try {
            DB::select('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (\Throwable $e) {
            report($e);
        }
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

    private function specialAttachmentForeignKey(): string
    {
        return $this->hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
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
