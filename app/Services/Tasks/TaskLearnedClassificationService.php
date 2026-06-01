<?php

namespace App\Services\Tasks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TaskLearnedClassificationService
{
    private const TABLE = 'task_classification_examples';

    private const ACCEPTED_CONFIDENCE = ['medium' => true, 'high' => true];

    public function lookup(string $normalizedTitle): ?array
    {
        $normalizedTitle = trim($normalizedTitle);
        if ($normalizedTitle === '' || ! $this->supportsStorage()) {
            return null;
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('normalized_title_hash', $this->hash($normalizedTitle))
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        $classification = [
            'task_category' => (string) ($row->task_category ?? ''),
            'effort_score' => (float) ($row->effort_score ?? 0),
            'classification_confidence' => (string) ($row->classification_confidence ?? 'medium'),
            'classification_source' => 'ai_cache',
            'classification_origin' => 'learned_cache',
            'user_override' => false,
            'matched_pattern' => $row->matched_pattern ?? null,
            'work_type' => (string) ($row->work_type ?? 'unclear'),
            'work_type_confidence' => (string) ($row->work_type_confidence ?? 'medium'),
            'work_type_matched_pattern' => $row->work_type_matched_pattern ?? null,
        ];

        return $this->validClassification($classification) ? $classification : null;
    }

    public function remember(string $title, string $normalizedTitle, array $classification): void
    {
        $normalizedTitle = trim($normalizedTitle);
        $title = trim($title);
        if (
            $title === ''
            || $normalizedTitle === ''
            || ! $this->supportsStorage()
            || ! $this->validClassification($classification)
            || ($classification['classification_source'] ?? null) !== 'ai'
        ) {
            return;
        }

        $now = now();
        $hash = $this->hash($normalizedTitle);

        try {
            $existing = DB::table(self::TABLE)->where('normalized_title_hash', $hash)->first();
            $payload = [
                'normalized_title' => $normalizedTitle,
                'sample_title' => substr($title, 0, 255),
                'task_category' => $classification['task_category'],
                'effort_score' => $classification['effort_score'],
                'classification_confidence' => $classification['classification_confidence'],
                'classification_source' => 'ai',
                'matched_pattern' => $classification['matched_pattern'] ?? null,
                'work_type' => TaskClassificationService::normalizeWorkType((string) ($classification['work_type'] ?? 'unclear')),
                'work_type_confidence' => $classification['work_type_confidence'] ?? $classification['classification_confidence'],
                'work_type_matched_pattern' => $classification['work_type_matched_pattern'] ?? null,
                'last_seen_at' => $now,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                DB::table(self::TABLE)->insert($payload + [
                    'normalized_title_hash' => $hash,
                    'usage_count' => 1,
                    'created_at' => $now,
                ]);

                return;
            }

            DB::table(self::TABLE)
                ->where('normalized_title_hash', $hash)
                ->update($payload + [
                    'usage_count' => (int) ($existing->usage_count ?? 0) + 1,
                ]);
        } catch (Throwable) {
            return;
        }
    }

    public function hash(string $normalizedTitle): string
    {
        return hash('sha256', trim($normalizedTitle));
    }

    private function validClassification(array $classification): bool
    {
        $category = TaskClassificationService::normalizeTaskCategory((string) ($classification['task_category'] ?? ''));
        if ($category !== (string) ($classification['task_category'] ?? '')) {
            return false;
        }

        if (abs((float) ($classification['effort_score'] ?? -1) - TaskClassificationService::effortScoreForCategory($category)) > 0.001) {
            return false;
        }

        $confidence = (string) ($classification['classification_confidence'] ?? '');
        if (! isset(self::ACCEPTED_CONFIDENCE[$confidence])) {
            return false;
        }

        $workType = (string) ($classification['work_type'] ?? '');
        if (TaskClassificationService::normalizeWorkType($workType) !== $workType) {
            return false;
        }

        return ! (($category === 'non_work' && $workType !== 'non_work') || ($category === 'unclear_unrated' && $workType !== 'unclear'));
    }

    private function supportsStorage(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, 'normalized_title_hash')
            && Schema::hasColumn(self::TABLE, 'task_category')
            && Schema::hasColumn(self::TABLE, 'effort_score')
            && Schema::hasColumn(self::TABLE, 'classification_confidence')
            && Schema::hasColumn(self::TABLE, 'work_type');
    }
}
