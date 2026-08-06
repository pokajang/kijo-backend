<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_feedbacks')) {
            return;
        }

        Schema::table('system_feedbacks', function (Blueprint $table): void {
            if (! Schema::hasColumn('system_feedbacks', 'verification_requested_at')) {
                $table->timestamp('verification_requested_at')->nullable()->index();
            }
            if (! Schema::hasColumn('system_feedbacks', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->index();
            }
            if (! Schema::hasColumn('system_feedbacks', 'resolved_by')) {
                $table->integer('resolved_by')->nullable()->index();
            }
        });

        if (! Schema::hasTable('system_feedback_history')) {
            Schema::create('system_feedback_history', function (Blueprint $table): void {
                $table->id();
                $table->integer('feedback_id')->index();
                $table->string('event_type', 50)->index();
                $table->integer('actor_staff_id')->nullable()->index();
                $table->string('actor_name')->nullable();
                $table->text('message')->nullable();
                $table->string('status_from', 50)->nullable();
                $table->string('status_to', 50)->nullable();
                $table->string('resolution_track_from', 50)->nullable();
                $table->string('resolution_track_to', 50)->nullable();
                $table->json('changes_json')->nullable();
                $table->timestamp('created_at');

                $table->index(['feedback_id', 'created_at'], 'system_feedback_history_feedback_created_idx');
            });
        }

        $this->backfillHistory();
    }

    public function down(): void
    {
        Schema::dropIfExists('system_feedback_history');

        if (! Schema::hasTable('system_feedbacks')) {
            return;
        }

        Schema::table('system_feedbacks', function (Blueprint $table): void {
            foreach (['verification_requested_at', 'resolved_at', 'resolved_by'] as $column) {
                if (Schema::hasColumn('system_feedbacks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillHistory(): void
    {
        if (! Schema::hasTable('system_feedback_history')) {
            return;
        }

        $staffNames = collect();
        if (Schema::hasTable('staff_general')) {
            $nameColumns = ['staff_id'];
            if (Schema::hasColumn('staff_general', 'name_code')) {
                $nameColumns[] = 'name_code';
            }
            if (Schema::hasColumn('staff_general', 'full_name')) {
                $nameColumns[] = 'full_name';
            }
            $staffNames = DB::table('staff_general')->get($nameColumns)->keyBy('staff_id');
        }

        DB::table('system_feedbacks')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($staffNames): void {
                foreach ($rows as $row) {
                    if (DB::table('system_feedback_history')->where('feedback_id', $row->id)->exists()) {
                        continue;
                    }

                    $staff = $staffNames->get($row->reported_by ?? null);
                    $actorName = trim((string) ($staff->name_code ?? $staff->full_name ?? '')) ?: null;
                    $reportedAt = $row->date_reported ?? now();

                    DB::table('system_feedback_history')->insert([
                        'feedback_id' => (int) $row->id,
                        'event_type' => 'report_received',
                        'actor_staff_id' => $row->reported_by ?? null,
                        'actor_name' => $actorName,
                        'message' => null,
                        'status_from' => null,
                        'status_to' => $row->status ?? 'Pending',
                        'resolution_track_from' => null,
                        'resolution_track_to' => $row->resolution_track ?? 'Needs Triage',
                        'changes_json' => null,
                        'created_at' => $reportedAt,
                    ]);

                    $hasLegacyState = ($row->status ?? 'Pending') !== 'Pending'
                        || ($row->resolution_track ?? 'Needs Triage') !== 'Needs Triage'
                        || ! empty($row->action_date)
                        || ! empty($row->fixed_at ?? null)
                        || trim((string) ($row->remarks ?? '')) !== '';

                    if (! $hasLegacyState) {
                        continue;
                    }

                    DB::table('system_feedback_history')->insert([
                        'feedback_id' => (int) $row->id,
                        'event_type' => 'legacy_state_imported',
                        'actor_staff_id' => null,
                        'actor_name' => 'System',
                        'message' => 'Current state imported when immutable feedback history was enabled.',
                        'status_from' => null,
                        'status_to' => $row->status ?? null,
                        'resolution_track_from' => null,
                        'resolution_track_to' => $row->resolution_track ?? null,
                        'changes_json' => json_encode([
                            'action_date' => ['from' => null, 'to' => $row->action_date ?? null],
                            'fixed_at' => ['from' => null, 'to' => $row->fixed_at ?? null],
                            'remarks' => ['from' => null, 'to' => $row->remarks ?? null],
                        ]),
                        'created_at' => now(),
                    ]);
                }
            });
    }
};
