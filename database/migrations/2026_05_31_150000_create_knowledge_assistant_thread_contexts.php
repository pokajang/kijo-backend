<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_assistant_thread_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id')->unique();
            $table->json('context_json');
            $table->unsignedBigInteger('last_processed_message_id')->nullable();
            $table->timestamps();

            $table->foreign('thread_id', 'knowledge_assistant_thread_contexts_thread_fk')
                ->references('id')
                ->on('knowledge_assistant_threads')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_assistant_thread_contexts');
    }
};
