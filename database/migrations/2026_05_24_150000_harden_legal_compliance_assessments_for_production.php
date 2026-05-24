<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_compliance_assessments')) {
            return;
        }

        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_compliance_assessments', 'project_id')) {
                $table->unsignedBigInteger('project_id')->nullable()->after('client_pic_email');
                $table->index('project_id', 'legal_compliance_assessments_project_idx');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'project_name')) {
                $table->string('project_name')->nullable()->after('project_id');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'parent_assessment_id')) {
                $table->unsignedBigInteger('parent_assessment_id')->nullable()->after('stage');
                $table->index('parent_assessment_id', 'legal_compliance_assessments_parent_idx');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(1)->after('parent_assessment_id');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'superseded_by_assessment_id')) {
                $table->unsignedBigInteger('superseded_by_assessment_id')->nullable()->after('revision_number');
                $table->index('superseded_by_assessment_id', 'legal_compliance_assessments_superseded_idx');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('updated_at');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'submitted_by_staff_id')) {
                $table->unsignedBigInteger('submitted_by_staff_id')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable()->after('submitted_by_staff_id');
                $table->index('deleted_at', 'legal_compliance_assessments_deleted_idx');
            }
            if (! Schema::hasColumn('legal_compliance_assessments', 'deleted_by_staff_id')) {
                $table->unsignedBigInteger('deleted_by_staff_id')->nullable()->after('deleted_at');
            }
        });

        DB::table('legal_compliance_assessments')
            ->whereNull('revision_number')
            ->update(['revision_number' => 1]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('legal_compliance_assessments')) {
            return;
        }

        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('legal_compliance_assessments', 'deleted_at')) {
                $table->dropIndex('legal_compliance_assessments_deleted_idx');
            }
            if (Schema::hasColumn('legal_compliance_assessments', 'superseded_by_assessment_id')) {
                $table->dropIndex('legal_compliance_assessments_superseded_idx');
            }
            if (Schema::hasColumn('legal_compliance_assessments', 'parent_assessment_id')) {
                $table->dropIndex('legal_compliance_assessments_parent_idx');
            }
            if (Schema::hasColumn('legal_compliance_assessments', 'project_id')) {
                $table->dropIndex('legal_compliance_assessments_project_idx');
            }
        });

        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            foreach ([
                'deleted_by_staff_id',
                'deleted_at',
                'submitted_by_staff_id',
                'submitted_at',
                'superseded_by_assessment_id',
                'revision_number',
                'parent_assessment_id',
                'project_name',
                'project_id',
            ] as $column) {
                if (Schema::hasColumn('legal_compliance_assessments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
