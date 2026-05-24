<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_compliance_template_versions')) {
            return;
        }

        if (! Schema::hasColumn('legal_compliance_template_versions', 'metadata')) {
            Schema::table('legal_compliance_template_versions', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('published_by');
            });
        }

        DB::table('legal_compliance_template_versions')
            ->whereNull('metadata')
            ->update([
                'metadata' => json_encode([
                    'change_note' => '',
                    'changed_by_staff_id' => null,
                    'changed_by_name' => 'System',
                ]),
            ]);
    }

    public function down(): void
    {
        if (
            Schema::hasTable('legal_compliance_template_versions')
            && Schema::hasColumn('legal_compliance_template_versions', 'metadata')
        ) {
            Schema::table('legal_compliance_template_versions', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }
    }
};
