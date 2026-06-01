<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_assistant_threads', function (Blueprint $table): void {
            $table->dropUnique('knowledge_assistant_threads_staff_id_unique');
            $table->index(['staff_id', 'last_message_at'], 'knowledge_assistant_threads_staff_last_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_assistant_threads', function (Blueprint $table): void {
            $table->dropIndex('knowledge_assistant_threads_staff_last_idx');
            $table->unique('staff_id');
        });
    }
};
