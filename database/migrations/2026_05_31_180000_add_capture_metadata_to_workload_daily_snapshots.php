<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workload_daily_snapshots')) {
            return;
        }

        Schema::table('workload_daily_snapshots', function (Blueprint $table): void {
            if (! Schema::hasColumn('workload_daily_snapshots', 'capture_mode')) {
                $table->string('capture_mode', 40)->default('captured')->after('payload_json');
            }
            if (! Schema::hasColumn('workload_daily_snapshots', 'captured_by_command')) {
                $table->string('captured_by_command', 120)->nullable()->after('capture_mode');
            }
            if (! Schema::hasColumn('workload_daily_snapshots', 'capture_note')) {
                $table->text('capture_note')->nullable()->after('captured_by_command');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workload_daily_snapshots')) {
            return;
        }

        Schema::table('workload_daily_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('workload_daily_snapshots', 'capture_note')) {
                $table->dropColumn('capture_note');
            }
            if (Schema::hasColumn('workload_daily_snapshots', 'captured_by_command')) {
                $table->dropColumn('captured_by_command');
            }
            if (Schema::hasColumn('workload_daily_snapshots', 'capture_mode')) {
                $table->dropColumn('capture_mode');
            }
        });
    }
};
