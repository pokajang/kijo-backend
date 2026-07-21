<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'in_app_notifications_recipient_dedupe_unique';

    public function up(): void
    {
        if (! Schema::hasTable('in_app_notifications') || Schema::hasColumn('in_app_notifications', 'dedupe_key')) {
            return;
        }

        Schema::table('in_app_notifications', function (Blueprint $table): void {
            $table->string('dedupe_key', 191)->nullable()->after('type');
            $table->unique(['recipient_staff_id', 'dedupe_key'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('in_app_notifications') || ! Schema::hasColumn('in_app_notifications', 'dedupe_key')) {
            return;
        }

        Schema::table('in_app_notifications', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->dropColumn('dedupe_key');
        });
    }
};
