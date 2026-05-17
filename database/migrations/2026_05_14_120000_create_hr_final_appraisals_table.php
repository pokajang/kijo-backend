<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_final_appraisals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id')->index();
            $table->date('appraisal_date')->index();
            $table->unsignedTinyInteger('work_quality');
            $table->unsignedTinyInteger('teamwork');
            $table->unsignedTinyInteger('leadership');
            $table->unsignedTinyInteger('overall_performance');
            $table->text('supervisor_comments');
            $table->string('salary_increment_recommendation')->nullable();
            $table->string('promotion_recommendation')->nullable();
            $table->unsignedBigInteger('created_by')->index();
            $table->timestamps();

            $table->index(['staff_id', 'appraisal_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_final_appraisals');
    }
};
