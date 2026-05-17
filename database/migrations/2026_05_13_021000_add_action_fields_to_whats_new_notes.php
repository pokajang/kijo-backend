<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whats_new_notes', function (Blueprint $table) {
            $table->string('action_label', 80)->nullable()->after('items');
            $table->string('action_path', 255)->nullable()->after('action_label');
        });
    }

    public function down(): void
    {
        Schema::table('whats_new_notes', function (Blueprint $table) {
            $table->dropColumn(['action_label', 'action_path']);
        });
    }
};
