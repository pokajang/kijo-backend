<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_compliance_assessments', 'client_pic_name')) {
                $table->string('client_pic_name')->nullable()->after('site_location');
            }

            if (! Schema::hasColumn('legal_compliance_assessments', 'client_pic_email')) {
                $table->string('client_pic_email')->nullable()->after('client_pic_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_compliance_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('legal_compliance_assessments', 'client_pic_email')) {
                $table->dropColumn('client_pic_email');
            }

            if (Schema::hasColumn('legal_compliance_assessments', 'client_pic_name')) {
                $table->dropColumn('client_pic_name');
            }
        });
    }
};
