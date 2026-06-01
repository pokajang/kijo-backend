<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workload_daily_snapshot_checks')) {
            Schema::create('workload_daily_snapshot_checks', function (Blueprint $table): void {
                $table->id();
                $table->date('snapshot_date')->index();
                $table->string('severity', 40)->index();
                $table->string('check_key', 80);
                $table->text('message')->nullable();
                $table->longText('metadata_json')->nullable();
                $table->timestamps();

                $table->unique(
                    ['snapshot_date', 'check_key'],
                    'workload_daily_snapshot_checks_unique',
                );
            });
        }

        if (Schema::hasTable('workload_daily_snapshots') && Schema::hasColumn('workload_daily_snapshots', 'payload_json')) {
            Schema::table('workload_daily_snapshots', function (Blueprint $table): void {
                $table->longText('payload_json')->nullable()->change();
            });
        }

        if (Schema::hasTable('workload_daily_staff_snapshots') && Schema::hasColumn('workload_daily_staff_snapshots', 'row_payload_json')) {
            Schema::table('workload_daily_staff_snapshots', function (Blueprint $table): void {
                $table->longText('row_payload_json')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_daily_snapshot_checks');

        if (Schema::hasTable('workload_daily_snapshots') && Schema::hasColumn('workload_daily_snapshots', 'payload_json')) {
            DB::table('workload_daily_snapshots')
                ->whereNull('payload_json')
                ->update(['payload_json' => '{}']);

            Schema::table('workload_daily_snapshots', function (Blueprint $table): void {
                $table->longText('payload_json')->nullable(false)->change();
            });
        }

        // row_payload_json was already nullable in the original snapshot table migration.
    }
};
