<?php

namespace App\Services\Assistant\UserTrace;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserEmploymentTraceAnalyzer
{
    public function analyze(string $question, AssistantUserTraceIdentity $identity, array $dateRange): AssistantUserTraceResult
    {
        $missing = [];
        $profile = null;
        if (Schema::hasTable('staff_general') && $identity->staffId > 0) {
            $profile = DB::table('staff_general')->where('staff_id', $identity->staffId)->first();
        }
        if (! $profile) {
            $missing[] = 'staff_general.profile';
        }

        $join = $profile ? $this->joinDate((array) $profile) : null;
        if (! $join) {
            $missing[] = 'staff_general.join_date';
        }

        $tenure = $join ? $this->tenure($join) : null;
        $position = $identity->position;
        $joinDateSource = $profile && $join ? $this->joinDateSource((array) $profile) : null;

        return new AssistantUserTraceResult(
            'user_trace.employment_tenure',
            'My employment trace',
            'Current user employment profile and tenure. Tenure uses an explicit join/hire/employment date when available, otherwise staff profile created_at.',
            $dateRange,
            [
                'join_date' => $join,
                'join_date_source' => $joinDateSource,
                'tenure_years' => $tenure['years_decimal'] ?? null,
                'tenure_label' => $tenure['label'] ?? null,
            ],
            [],
            [array_filter([
                'staff_id' => $identity->staffId,
                'full_name' => $identity->fullName,
                'name_code' => $identity->nameCode,
                'department' => $identity->department,
                'position' => $position,
                'status' => $profile->status ?? null,
                'join_date_source' => $joinDateSource,
            ], static fn ($value): bool => $value !== null && $value !== '')],
            ['show my profile', 'what is my department', 'what is my current position'],
            $missing,
            $join ? 'medium' : 'low',
            $join
                ? "Based on your staff profile {$joinDateSource}, your tenure is {$tenure['label']} as of ".Carbon::today()->toDateString().'.'
                : 'I could not calculate your tenure because a reliable join date is not available in your staff profile.',
            '/my/profile',
            ['analyzer' => 'employment', 'profile_found' => $profile !== null],
        );
    }

    private function joinDate(array $profile): ?string
    {
        foreach (['join_date', 'date_joined', 'joined_at', 'hire_date', 'employment_date', 'start_date', 'created_at'] as $column) {
            if (! empty($profile[$column])) {
                return substr((string) $profile[$column], 0, 10);
            }
        }

        return null;
    }

    private function joinDateSource(array $profile): ?string
    {
        foreach (['join_date', 'date_joined', 'joined_at', 'hire_date', 'employment_date', 'start_date', 'created_at'] as $column) {
            if (! empty($profile[$column])) {
                return $column;
            }
        }

        return null;
    }

    private function tenure(string $joinDate): array
    {
        $join = Carbon::parse($joinDate);
        $now = Carbon::today();
        $interval = $join->diff($now);
        $decimal = round($join->diffInDays($now) / 365.25, 2);

        return [
            'years_decimal' => $decimal,
            'label' => "{$interval->y} year(s), {$interval->m} month(s)",
        ];
    }
}
