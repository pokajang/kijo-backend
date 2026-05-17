<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_new_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whats_new_note_id');
            $table->string('file_path', 255);
            $table->string('original_name', 191);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size');
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('whats_new_note_id', 'whats_new_attachments_note_fk')
                ->references('id')
                ->on('whats_new_notes')
                ->cascadeOnDelete();
            $table->index(['whats_new_note_id', 'sort_order'], 'whats_new_attachments_note_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_new_attachments');
    }
};
