<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FeedbackSlaSmokeSeeder extends Seeder
{
    private const STAFF_EMAIL = 'sla-smoke@kijo.local';
    private const STAFF_CODE = 'SLASMOKE';

    public function run(): void
    {
        if (! Schema::hasTable('system_feedbacks')) {
            $this->command?->error('system_feedbacks table does not exist.');
            return;
        }

        $staffId = $this->resolveStaffId();
        $now = CarbonImmutable::now();
        $year = (int) $now->year;
        $currentMonthReportedAt = $now->subDays(10)->startOfDay();

        $records = [
            [
                'feedback' => '[SLA Smoke] January fixed within 30 days',
                'reported_at' => CarbonImmutable::create($year, 1, 5, 9),
                'status' => 'Fixed Completed',
                'resolution_track' => '30-Day Fix',
                'fixed_at' => CarbonImmutable::create($year, 1, 20, 9),
                'remarks' => 'Smoke seed: visible successful SLA bar.',
            ],
            [
                'feedback' => '[SLA Smoke] February fixed after 30 days',
                'reported_at' => CarbonImmutable::create($year, 2, 1, 9),
                'status' => 'Fixed Completed',
                'resolution_track' => '30-Day Fix',
                'fixed_at' => CarbonImmutable::create($year, 3, 10, 9),
                'remarks' => 'Smoke seed: visible missed SLA bar.',
            ],
            [
                'feedback' => '[SLA Smoke] March pending past 30 days',
                'reported_at' => CarbonImmutable::create($year, 3, 1, 9),
                'status' => 'Pending',
                'resolution_track' => '30-Day Fix',
                'fixed_at' => null,
                'remarks' => 'Smoke seed: matured open item counted as missed.',
            ],
            [
                'feedback' => '[SLA Smoke] Current month open within 30 days',
                'reported_at' => $currentMonthReportedAt,
                'status' => 'Pending',
                'resolution_track' => '30-Day Fix',
                'fixed_at' => null,
                'remarks' => 'Smoke seed: current month provisional pending bar.',
            ],
            [
                'feedback' => '[SLA Smoke] Current month needs triage reference',
                'reported_at' => $currentMonthReportedAt,
                'status' => 'Pending',
                'resolution_track' => 'Needs Triage',
                'fixed_at' => null,
                'remarks' => 'Smoke seed: non-SLA row for excluded/triage counts.',
            ],
        ];

        DB::transaction(function () use ($records, $staffId, $now): void {
            foreach ($records as $record) {
                $payload = [
                    'feedback' => $record['feedback'],
                    'reported_by' => $staffId,
                    'date_reported' => $record['reported_at']->toDateTimeString(),
                    'status' => $record['status'],
                    'action_date' => $record['fixed_at']?->toDateTimeString(),
                    'remarks' => $record['remarks'],
                ];

                if (Schema::hasColumn('system_feedbacks', 'resolution_track')) {
                    $payload['resolution_track'] = $record['resolution_track'];
                }

                if (Schema::hasColumn('system_feedbacks', 'fixed_at')) {
                    $payload['fixed_at'] = $record['fixed_at']?->toDateTimeString();
                }

                if (Schema::hasColumn('system_feedbacks', 'updated_at')) {
                    $payload['updated_at'] = $now->toDateTimeString();
                }

                DB::table('system_feedbacks')->updateOrInsert(
                    ['feedback' => $record['feedback']],
                    $payload,
                );
            }
        });

        $this->command?->info('Feedback SLA smoke rows seeded.');
    }

    private function resolveStaffId(): int
    {
        $staff = DB::table('staff_general')
            ->where('email', 'azam@amiosh.com')
            ->orWhere('name_code', 'AZA')
            ->first();

        if ($staff?->staff_id) {
            return (int) $staff->staff_id;
        }

        $staff = DB::table('staff_general')
            ->where('email', self::STAFF_EMAIL)
            ->orWhere('name_code', self::STAFF_CODE)
            ->first();

        if ($staff?->staff_id) {
            return (int) $staff->staff_id;
        }

        return (int) DB::table('staff_general')->insertGetId([
            'full_name' => 'SLA Smoke User',
            'name_code' => self::STAFF_CODE,
            'email' => self::STAFF_EMAIL,
            'position' => 'Smoke Test',
            'department' => 'Support',
            'status' => 'Active',
            'grant_access' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
