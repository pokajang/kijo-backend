<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whats_new_notes', function (Blueprint $table) {
            $table->id();
            $table->string('version', 191)->unique();
            $table->string('title', 191);
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->json('items')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by_staff_id')->nullable();
            $table->unsignedBigInteger('updated_by_staff_id')->nullable();
            $table->timestamps();

            $table->index(['is_published', 'published_at'], 'whats_new_notes_published_idx');
        });

        Schema::create('whats_new_reads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whats_new_note_id');
            $table->unsignedBigInteger('staff_id');
            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['whats_new_note_id', 'staff_id'], 'whats_new_reads_note_staff_unique');
            $table->index('staff_id', 'whats_new_reads_staff_idx');
            $table->foreign('whats_new_note_id', 'whats_new_reads_note_fk')
                ->references('id')
                ->on('whats_new_notes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whats_new_reads');
        Schema::dropIfExists('whats_new_notes');
    }
};
