<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_manual_pipeline_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_type', 32);
            $table->string('prospect_name', 191);
            $table->date('entry_date');
            $table->string('source', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('owner_staff_id')->nullable();
            $table->string('owner_staff_code', 32)->nullable();
            $table->string('owner_staff_name', 191)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_code', 32)->nullable();
            $table->timestamps();

            $table->index(['entry_type', 'entry_date'], 'monitoring_manual_entries_type_date_idx');
            $table->index(['owner_staff_code', 'entry_date'], 'monitoring_manual_entries_owner_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_manual_pipeline_entries');
    }
};
