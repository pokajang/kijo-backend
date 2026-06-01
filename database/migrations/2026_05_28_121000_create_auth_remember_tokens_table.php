<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_remember_tokens')) {
            return;
        }

        Schema::create('auth_remember_tokens', function (Blueprint $table): void {
            $table->string('selector', 64)->primary();
            $table->foreignId('user_id')->index();
            $table->string('token_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_remember_tokens');
    }
};
