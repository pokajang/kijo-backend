<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_compliance_assessments', 'client_company_id')) {
                $table->unsignedBigInteger('client_company_id')->nullable()->after('site_location');
                $table->index('client_company_id', 'legal_compliance_assessments_client_company_idx');
            }

            if (! Schema::hasColumn('legal_compliance_assessments', 'client_branch_id')) {
                $table->unsignedBigInteger('client_branch_id')->nullable()->after('client_company_id');
            }

            if (! Schema::hasColumn('legal_compliance_assessments', 'client_pic_id')) {
                $table->unsignedBigInteger('client_pic_id')->nullable()->after('client_branch_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('legal_compliance_assessments', 'client_company_id')) {
                $table->dropIndex('legal_compliance_assessments_client_company_idx');
            }

            if (Schema::hasColumn('legal_compliance_assessments', 'client_pic_id')) {
                $table->dropColumn('client_pic_id');
            }

            if (Schema::hasColumn('legal_compliance_assessments', 'client_branch_id')) {
                $table->dropColumn('client_branch_id');
            }

            if (Schema::hasColumn('legal_compliance_assessments', 'client_company_id')) {
                $table->dropColumn('client_company_id');
            }
        });
    }
};
