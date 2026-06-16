<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_value_revision_documents')) {
            return;
        }

        Schema::create('project_value_revision_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_value_revision_id');
            $table->unsignedBigInteger('project_id');
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_ref')->nullable();
            $table->string('action', 80);
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->decimal('new_amount', 15, 2)->nullable();
            $table->string('status_before')->nullable();
            $table->string('status_after')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index(['project_value_revision_id'], 'pvr_docs_revision_idx');
            $table->index(['project_id', 'document_type'], 'pvr_docs_project_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_value_revision_documents');
    }
};
