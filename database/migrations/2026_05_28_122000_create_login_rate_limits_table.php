<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_rate_limits')) {
            return;
        }

        Schema::create('login_rate_limits', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32);
            $table->string('bucket_key', 64);
            $table->unsignedInteger('window_seconds');
            $table->timestamp('window_start');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('blocked_until')->nullable()->index();

            $table->unique(
                ['scope', 'bucket_key', 'window_seconds', 'window_start'],
                'login_rate_limits_bucket_window_unique',
            );
            $table->index(['scope', 'bucket_key', 'window_seconds'], 'login_rate_limits_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_rate_limits');
    }
};
