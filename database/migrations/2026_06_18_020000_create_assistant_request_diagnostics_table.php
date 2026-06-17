<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_request_diagnostics')) {
            return;
        }

        Schema::create('assistant_request_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('message_id')->unique();
            $table->unsignedBigInteger('thread_id')->nullable()->index();
            $table->string('question_hash', 64)->index();
            $table->text('question')->nullable();
            $table->string('current_route', 255)->nullable()->index();
            $table->json('diagnostics_json');
            $table->timestamps();

            $table->foreign('message_id', 'assistant_request_diagnostics_message_fk')
                ->references('id')
                ->on('knowledge_assistant_messages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_request_diagnostics');
    }
};
