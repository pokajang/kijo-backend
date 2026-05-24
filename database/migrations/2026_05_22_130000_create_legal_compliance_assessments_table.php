<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_compliance_assessments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->string('template_version', 50)->default('osha-1994-v1');
            $table->string('stage', 50)->default('details');
            $table->string('company_name')->nullable();
            $table->text('site_location')->nullable();
            $table->date('assessment_date')->nullable();
            $table->text('assessor_name')->nullable();
            $table->text('assessor_email')->nullable();
            $table->text('nature_of_company')->nullable();
            $table->json('selected_assessors')->nullable();
            $table->json('clause_responses')->nullable();
            $table->timestamps();

            $table->index(['staff_id', 'updated_at'], 'legal_compliance_staff_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_compliance_assessments');
    }
};
