<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_assistant_threads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id')->unique();
            $table->string('title', 191)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('knowledge_assistant_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->string('role', 20);
            $table->longText('content');
            $table->json('sources_json')->nullable();
            $table->string('confidence', 20)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamps();

            $table->foreign('thread_id', 'knowledge_assistant_messages_thread_fk')
                ->references('id')
                ->on('knowledge_assistant_threads')
                ->cascadeOnDelete();
            $table->index(['thread_id', 'created_at'], 'knowledge_assistant_messages_thread_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_assistant_messages');
        Schema::dropIfExists('knowledge_assistant_threads');
    }
};
