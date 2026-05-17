<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Meetings\MeetingActionItemService;
use App\Services\Meetings\MeetingPdfService;
use App\Services\Meetings\MeetingQueryService;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\MeetingVerificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MeetingController extends Controller
{
    private function actionItemService(): MeetingActionItemService
    {
        return app(MeetingActionItemService::class);
    }

    private function pdfService(): MeetingPdfService
    {
        return app(MeetingPdfService::class);
    }

    private function queryService(): MeetingQueryService
    {
        return app(MeetingQueryService::class);
    }

    private function meetingService(): MeetingService
    {
        return app(MeetingService::class);
    }

    private function verificationService(): MeetingVerificationService
    {
        return app(MeetingVerificationService::class);
    }

    public function index(Request $request)
    {
        $this->ensureMeetingTables();

        return $this->queryService()->index($request);
    }

    public function show(Request $request, int $id)
    {
        $this->ensureMeetingTables();

        return $this->queryService()->show($request, $id);
    }

    public function store(Request $request)
    {
        $this->ensureMeetingTables();

        return $this->meetingService()->store($request);
    }

    public function update(Request $request, int $id)
    {
        $this->ensureMeetingTables();

        return $this->meetingService()->update($request, $id);
    }

    public function destroy(Request $request, int $id)
    {
        $this->ensureMeetingTables();

        return $this->meetingService()->destroy($request, $id);
    }

    public function addActionItem(Request $request)
    {
        $this->ensureMeetingTables();

        return $this->actionItemService()->add($request);
    }

    public function updateActionItemStatus(Request $request)
    {
        $this->ensureMeetingTables();

        return $this->actionItemService()->updateStatus($request);
    }

    public function updateVerification(Request $request)
    {
        $this->ensureMeetingTables();

        return $this->verificationService()->update($request);
    }

    public function exportPdf(Request $request, int $id)
    {
        if ($id <= 0) {
            return response('Invalid meeting id', 422);
        }

        try {
            $this->ensureMeetingTables();
        } catch (\Throwable $e) {
            report($e);
            return response('Server error.', 500);
        }

        return $this->pdfService()->export($request, $id);
    }

    private function ensureMeetingTables(): void
    {
        $requiredTables = [
            'meeting_minutes',
            'meeting_minute_attendees',
            'meeting_minute_audit_logs',
            'meeting_minute_comments',
        ];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Meeting schema is not ready. Please run schema sync first.');
            }
        }

        $this->ensureMeetingDraftColumns();
    }

    private function ensureMeetingDraftColumns(): void
    {
        if (! Schema::hasColumn('meeting_minutes', 'record_status')) {
            Schema::table('meeting_minutes', function (Blueprint $table): void {
                $column = $table->string('record_status', 20)->default('Complete');
                if (Schema::hasColumn('meeting_minutes', 'updated_code')) {
                    $column->after('updated_code');
                }
            });
        }

        if (! Schema::hasColumn('meeting_minutes', 'draft_key')) {
            Schema::table('meeting_minutes', function (Blueprint $table): void {
                $column = $table->string('draft_key', 64)->nullable();
                if (Schema::hasColumn('meeting_minutes', 'record_status')) {
                    $column->after('record_status');
                }
            });
        }

        if (! $this->meetingIndexExists('idx_record_status')) {
            Schema::table('meeting_minutes', function (Blueprint $table): void {
                $table->index('record_status', 'idx_record_status');
            });
        }

        if (! $this->meetingIndexExists('uq_meeting_created_draft_key')) {
            $this->clearDuplicateDraftKeysBeforeUniqueIndex();
            Schema::table('meeting_minutes', function (Blueprint $table): void {
                $table->unique(['created_by', 'draft_key'], 'uq_meeting_created_draft_key');
            });
        }

        $recordStatusBackfill = Schema::hasColumn('meeting_minutes', 'verification_status')
            ? DB::raw("
                CASE
                    WHEN verification_status = 'Pending' AND TRIM(COALESCE(minutes_text, '')) = '' THEN 'Draft'
                    ELSE 'Complete'
                END
            ")
            : 'Complete';

        DB::table('meeting_minutes')
            ->where(function ($query): void {
                $query->whereNull('record_status')
                    ->orWhere('record_status', '')
                    ->orWhereNotIn('record_status', ['Draft', 'Complete', 'Discarded']);
            })
            ->update(['record_status' => $recordStatusBackfill]);
    }

    private function clearDuplicateDraftKeysBeforeUniqueIndex(): void
    {
        if (! Schema::hasColumn('meeting_minutes', 'created_by') || ! Schema::hasColumn('meeting_minutes', 'draft_key')) {
            return;
        }

        $duplicateGroups = DB::table('meeting_minutes')
            ->select(['created_by', 'draft_key', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as duplicate_count')])
            ->whereNotNull('draft_key')
            ->where('draft_key', '<>', '')
            ->groupBy(['created_by', 'draft_key'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            DB::table('meeting_minutes')
                ->where('created_by', $group->created_by)
                ->where('draft_key', $group->draft_key)
                ->where('id', '<>', (int) $group->keep_id)
                ->update(['draft_key' => null]);
        }
    }

    private function meetingIndexExists(string $indexName): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('meeting_minutes')");
            foreach ($indexes as $index) {
                if ((string) ($index->name ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'meeting_minutes')
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

}
