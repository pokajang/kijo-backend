<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TRACK_NEEDS_TRIAGE = 'Needs Triage';
    private const TRACK_30_DAY_FIX = '30-Day Fix';
    private const TRACK_NEXT_UPGRADE = 'Next Upgrade';
    private const COMPLETED_STATUS = 'Fixed Completed';
    private const SLA_DAYS = 30;

    public function up(): void
    {
        if (! Schema::hasColumn('system_feedbacks', 'resolution_track')) {
            Schema::table('system_feedbacks', function (Blueprint $table): void {
                $table
                    ->string('resolution_track', 50)
                    ->default(self::TRACK_NEEDS_TRIAGE)
                    ->index()
                    ->after('status');
            });
        }

        DB::table('system_feedbacks')->update([
            'resolution_track' => self::TRACK_NEEDS_TRIAGE,
        ]);

        DB::table('system_feedbacks')
            ->select(['id', 'date_reported', 'fixed_at'])
            ->where('status', self::COMPLETED_STATUS)
            ->whereNotNull('date_reported')
            ->whereNotNull('fixed_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $reportedAt = CarbonImmutable::parse($row->date_reported)->startOfDay();
                    $fixedAt = CarbonImmutable::parse($row->fixed_at)->startOfDay();

                    DB::table('system_feedbacks')
                        ->where('id', $row->id)
                        ->update([
                            'resolution_track' => $fixedAt->lte($reportedAt->addDays(self::SLA_DAYS))
                                ? self::TRACK_30_DAY_FIX
                                : self::TRACK_NEXT_UPGRADE,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('system_feedbacks', 'resolution_track')) {
            Schema::table('system_feedbacks', function (Blueprint $table): void {
                $table->dropColumn('resolution_track');
            });
        }
    }
};
