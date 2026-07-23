<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_other_claim_applications')) {
            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_other_claim_applications', 'claim_reference')) {
                    $table->string('claim_reference', 40)->nullable()->after('id');
                }
                if (! Schema::hasColumn('hr_other_claim_applications', 'revision_no')) {
                    $table->unsignedInteger('revision_no')->default(1)->after('claim_reference');
                }
                if (! Schema::hasColumn('hr_other_claim_applications', 'parent_application_id')) {
                    $table->unsignedBigInteger('parent_application_id')->nullable()->after('revision_no');
                }
                if (! Schema::hasColumn('hr_other_claim_applications', 'superseded_by_application_id')) {
                    $table->unsignedBigInteger('superseded_by_application_id')->nullable()->after('parent_application_id');
                }
                if (! Schema::hasColumn('hr_other_claim_applications', 'superseded_at')) {
                    $table->timestamp('superseded_at')->nullable()->after('superseded_by_application_id');
                }
                if (! Schema::hasColumn('hr_other_claim_applications', 'record_version')) {
                    $table->unsignedInteger('record_version')->default(1)->after('status');
                }
            });

            DB::table('hr_other_claim_applications')
                ->whereNull('claim_reference')
                ->orderBy('id')
                ->chunkById(100, function ($records): void {
                    foreach ($records as $record) {
                        DB::table('hr_other_claim_applications')
                            ->where('id', $record->id)
                            ->update([
                                'claim_reference' => sprintf('OC-%06d', $record->id),
                                'revision_no' => max(1, (int) ($record->revision_no ?? 1)),
                                'record_version' => max(1, (int) ($record->record_version ?? 1)),
                            ]);
                    }
                });

            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                $table->unique(['claim_reference', 'revision_no'], 'other_claim_reference_revision_unique');
                $table->index(['parent_application_id', 'revision_no'], 'other_claim_parent_revision_index');
                $table->index('superseded_by_application_id', 'other_claim_superseded_by_index');
            });
        }

        if (Schema::hasTable('hr_other_claim_attachments') && ! Schema::hasColumn('hr_other_claim_attachments', 'source_attachment_id')) {
            Schema::table('hr_other_claim_attachments', function (Blueprint $table): void {
                $table->unsignedBigInteger('source_attachment_id')->nullable()->after('claim_id');
                $table->index('source_attachment_id', 'other_claim_attachment_source_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_other_claim_attachments') && Schema::hasColumn('hr_other_claim_attachments', 'source_attachment_id')) {
            Schema::table('hr_other_claim_attachments', function (Blueprint $table): void {
                $table->dropIndex('other_claim_attachment_source_index');
                $table->dropColumn('source_attachment_id');
            });
        }

        if (! Schema::hasTable('hr_other_claim_applications')) {
            return;
        }

        Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
            $table->dropIndex('other_claim_reference_revision_unique');
            $table->dropIndex('other_claim_parent_revision_index');
            $table->dropIndex('other_claim_superseded_by_index');
            $table->dropColumn([
                'claim_reference',
                'revision_no',
                'parent_application_id',
                'superseded_by_application_id',
                'superseded_at',
                'record_version',
            ]);
        });
    }
};
