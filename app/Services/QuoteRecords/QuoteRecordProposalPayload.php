<?php

namespace App\Services\QuoteRecords;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteRecordProposalPayload
{
    private const CONFIG = [
        'training' => [
            'id_field' => 'proposal_id',
            'table' => 'proposal_template_training_main',
            'title_column' => 'training_title',
        ],
        'ih' => [
            'id_field' => 'service_id',
            'table' => 'proposal_template_ih',
            'title_column' => 'service_title',
        ],
        'manpower' => [
            'id_field' => 'mp_id',
            'table' => 'proposal_template_manpower',
            'title_column' => 'service_title',
        ],
        'special' => [
            'id_field' => 'sp_id',
            'table' => 'proposal_template_special',
            'title_column' => 'service_title',
        ],
    ];

    public static function attach(array &$rows, string $type): void
    {
        if ($type === 'equipment') {
            foreach ($rows as &$row) {
                self::set($row, 'proposal', self::equipment($row));
            }
            unset($row);

            return;
        }

        $config = self::CONFIG[$type] ?? null;
        if (! $config) {
            return;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = self::positiveInt(self::value($row, $config['id_field']));
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $templates = self::templatesById($config, array_values(array_unique($ids)));

        foreach ($rows as &$row) {
            $templateId = self::positiveInt(self::value($row, $config['id_field']));
            $template = $templateId !== null ? ($templates[$templateId] ?? null) : null;

            self::set($row, 'proposal', [
                'attachedToPdf' => self::truthy(self::value($row, 'attach_proposal')),
                'templateType' => $type,
                'templateId' => $templateId,
                'title' => $template?->title,
                'language' => self::proposalLanguage($row, $template),
                'canPreviewInline' => $template !== null,
            ]);
        }
        unset($row);
    }

    private static function equipment(mixed $row): array
    {
        return [
            'attachedToPdf' => self::truthy(self::value($row, 'attach_proposal')),
            'templateType' => null,
            'templateId' => null,
            'title' => null,
            'language' => null,
            'canPreviewInline' => false,
        ];
    }

    private static function templatesById(array $config, array $ids): array
    {
        if (empty($ids) || ! Schema::hasTable($config['table'])) {
            return [];
        }

        $query = DB::table($config['table'])
            ->whereIn('id', $ids)
            ->select([
                'id',
                DB::raw($config['title_column'].' as title'),
            ]);

        if (Schema::hasColumn($config['table'], 'proposal_language')) {
            $query->addSelect('proposal_language');
        }

        if (Schema::hasColumn($config['table'], 'is_deleted')) {
            $query->where(function ($query): void {
                $query->where('is_deleted', 0)->orWhereNull('is_deleted');
            });
        }

        return $query->get()->keyBy('id')->all();
    }

    private static function proposalLanguage(mixed $row, ?object $template): ?string
    {
        $language = self::value($row, 'proposal_language') ?: ($template->proposal_language ?? null);

        return in_array($language, ['en', 'ms-MY'], true) ? $language : null;
    }

    private static function value(mixed $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    private static function set(mixed &$row, string $key, mixed $value): void
    {
        if (is_array($row)) {
            $row[$key] = $value;

            return;
        }

        $row->{$key} = $value;
    }

    private static function positiveInt(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
