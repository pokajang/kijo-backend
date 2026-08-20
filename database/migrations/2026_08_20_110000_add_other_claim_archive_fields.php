<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_other_claim_applications')) {
            return;
        }

        Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_other_claim_applications', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('cancel_reason');
            }
            if (! Schema::hasColumn('hr_other_claim_applications', 'archived_by')) {
                $table->unsignedBigInteger('archived_by')->nullable()->after('archived_at');
            }
            if (! Schema::hasColumn('hr_other_claim_applications', 'archive_reason')) {
                $table->text('archive_reason')->nullable()->after('archived_by');
            }
        });

        try {
            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                $table->index(['staff_id', 'archived_at'], 'other_claim_staff_archived_idx');
            });
        } catch (Throwable) {
            // The index may already exist on a drifted database.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_other_claim_applications')) {
            return;
        }

        try {
            Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
                $table->dropIndex('other_claim_staff_archived_idx');
            });
        } catch (Throwable) {
            // The index may be absent on a drifted database.
        }

        Schema::table('hr_other_claim_applications', function (Blueprint $table): void {
            foreach (['archive_reason', 'archived_by', 'archived_at'] as $column) {
                if (Schema::hasColumn('hr_other_claim_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
