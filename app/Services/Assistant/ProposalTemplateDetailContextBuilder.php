<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProposalTemplateDetailContextBuilder
{
    public function __construct(
        private readonly AssistantContextSanitizer $sanitizer,
        private readonly AssistantRecordRouteParser $routes,
    ) {}

    public function candidateRows(?string $type = null): array
    {
        $rows = [];
        foreach ($this->types($type) as $proposalType) {
            $config = $this->config($proposalType);
            if (! $config || ! Schema::hasTable($config['table'])) {
                continue;
            }

            $titleFields = $config['title_fields'] ?? [$config['title']];
            $columns = $this->existingColumns($config['table'], array_values(array_unique(array_merge(
                ['id', $config['code'], 'created_at', 'updated_at', 'is_deleted', 'status', 'proposal_language', 'source_template_id', 'translation_status'],
                $titleFields,
                $config['search'],
                $config['identifiers'] ?? [],
            ))));
            if ($columns === []) {
                continue;
            }

            $query = DB::table($config['table'])->select($columns);
            if (in_array('updated_at', $columns, true)) {
                $query->orderByDesc('updated_at');
            }

            foreach ($query->get() as $row) {
                $item = (array) $row;
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $identifiers = array_values(array_unique(array_filter(array_map(
                    fn (string $field): string => trim((string) ($item[$field] ?? '')),
                    array_values(array_unique(array_merge([$config['code']], $config['identifiers'] ?? []))),
                ))));
                $item['_assistant_id'] = $id;
                $item['_assistant_type'] = $proposalType;
                $item['_assistant_title'] = $this->firstText($item, $titleFields) ?: $config['label'].' #'.$id;
                $item['_assistant_code'] = trim((string) ($item[$config['code']] ?? ''));
                $item['_assistant_identifiers'] = $identifiers;
                $item['_assistant_status'] = $this->statusFor($item);
                $item['_assistant_is_deleted'] = array_key_exists('is_deleted', $item) ? (bool) $item['is_deleted'] : null;
                $item['_assistant_language'] = $item['proposal_language'] ?? null;
                $item['_assistant_service_type'] = implode(' ', array_map(
                    fn (string $field): string => strip_tags((string) ($item[$field] ?? '')),
                    $config['service_type'] ?? [],
                ));
                $item['_assistant_search'] = implode(' ', array_map(
                    fn (string $field): string => strip_tags((string) ($item[$field] ?? '')),
                    $config['search'],
                ));
                $rows[] = $item;
            }
        }

        return $rows;
    }

    public function detail(string $type, int $id): ?array
    {
        $config = $this->config($type);
        if (! $config || $id <= 0 || ! Schema::hasTable($config['table'])) {
            return null;
        }

        $columns = $this->existingColumns($config['table'], array_values(array_unique(array_merge(
            ['id', 'created_at', 'updated_at'],
            $config['detail'],
            $config['title_fields'] ?? [$config['title']],
        ))));
        if ($columns === []) {
            return null;
        }

        $row = DB::table($config['table'])->select($columns)->where('id', $id)->first();
        if (! $row) {
            return null;
        }

        $payload = $this->mapRow($type, (array) $row, $config);
        if ($type === 'training') {
            $payload['agenda'] = $this->trainingAgenda($id);
        }
        $payload['history'] = $this->historyRows($config['history'], $id);
        if ($type === 'special') {
            $payload['attachments'] = $this->specialAttachments($id);
        }

        return $this->sanitizer->detail($payload);
    }

    public function titleFor(string $type, array $detail, int $id): string
    {
        return (string) (
            $detail['title']
            ?? $detail['trainingTitle']
            ?? $detail['serviceTitle']
            ?? $this->config($type)['label'].' #'.$id
        );
    }

    public function routeFor(string $type, int $id): string
    {
        return $this->routes->proposalDetailRoute($type, $id);
    }

    public function typeLabel(string $type): string
    {
        return (string) ($this->config($type)['label'] ?? ucfirst($type).' Proposal');
    }

    private function mapRow(string $type, array $row, array $config): array
    {
        $base = [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $type,
            'typeLabel' => $config['label'],
            'title' => $this->firstText($row, $config['title_fields'] ?? [$config['title']]),
            'code' => $row[$config['code']] ?? null,
            'status' => $this->statusFor($row),
            'isDeleted' => array_key_exists('is_deleted', $row) ? (bool) $row['is_deleted'] : null,
            'proposalLanguage' => $row['proposal_language'] ?? 'en',
            'sourceTemplateId' => $row['source_template_id'] ?? null,
            'translationProvider' => $row['translation_provider'] ?? null,
            'translationStatus' => $row['translation_status'] ?? null,
            'translatedAt' => $row['translated_at'] ?? null,
            'translationNotes' => $row['translation_notes'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        $sections = [];
        foreach ($config['sections'] as $label => $column) {
            if (array_key_exists($column, $row) && trim(strip_tags((string) ($row[$column] ?? ''))) !== '') {
                $sections[] = ['title' => $label, 'content' => $row[$column]];
            }
        }

        return array_filter(
            $base + [
                'sections' => $sections,
                'rawFields' => $this->sanitizer->keepDetail($row, $config['detail']),
            ],
            fn ($value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    private function trainingAgenda(int $templateId): array
    {
        if (! Schema::hasTable('proposal_template_training_agenda')) {
            return [];
        }

        $topicColumn = Schema::hasColumn('proposal_template_training_agenda', 'topic') ? 'topic' : 'activity';
        if (! Schema::hasColumn('proposal_template_training_agenda', $topicColumn)) {
            return [];
        }
        $columns = $this->existingColumns('proposal_template_training_agenda', [
            'id',
            'template_id',
            'day',
            'start_time',
            'end_time',
            $topicColumn,
        ]);
        if ($columns === []) {
            return [];
        }

        $query = DB::table('proposal_template_training_agenda')
            ->select($columns)
            ->where('template_id', $templateId);
        foreach (['day', 'start_time', 'id'] as $orderColumn) {
            if (in_array($orderColumn, $columns, true)) {
                $query->orderBy($orderColumn);
            }
        }

        return $query->get()
            ->map(fn (object $row): array => [
                'day' => (int) ($row->day ?? 1),
                'start_time' => $row->start_time ?? null,
                'end_time' => $row->end_time ?? null,
                'topic' => $row->{$topicColumn} ?? null,
            ])
            ->all();
    }

    private function historyRows(string $table, int $templateId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = $this->existingColumns($table, ['id', 'template_id', 'remarks', 'action', 'created_by', 'created_at']);
        if ($columns === [] || ! in_array('template_id', $columns, true)) {
            return [];
        }

        $query = DB::table($table)
            ->select($columns)
            ->where('template_id', $templateId);
        if (in_array('created_at', $columns, true)) {
            $query->orderByDesc('created_at');
        }

        $rows = $query->limit(8)->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        return $this->sanitizer->detailRows($rows, $columns, 8);
    }

    private function specialAttachments(int $templateId): array
    {
        if (! Schema::hasTable('proposal_special_attachments')) {
            return [];
        }

        $foreignKey = Schema::hasColumn('proposal_special_attachments', 'template_id') ? 'template_id' : 'proposal_id';
        $nameColumn = Schema::hasColumn('proposal_special_attachments', 'original_filename') ? 'original_filename' : 'file_name';
        $columns = $this->existingColumns('proposal_special_attachments', [
            'id',
            $foreignKey,
            $nameColumn,
            'mime_type',
            'file_size',
            'created_at',
        ]);
        if ($columns === [] || ! in_array($foreignKey, $columns, true)) {
            return [];
        }

        $query = DB::table('proposal_special_attachments')
            ->select($columns)
            ->where($foreignKey, $templateId);
        if (in_array('id', $columns, true)) {
            $query->orderBy('id');
        }

        return $query->limit(8)->get()
            ->map(fn (object $row): array => $this->sanitizer->detail((array) $row))
            ->all();
    }

    private function types(?string $type): array
    {
        return $type ? [$type] : ['training', 'ih', 'manpower', 'special'];
    }

    private function config(string $type): ?array
    {
        return match ($type) {
            'training' => [
                'table' => 'proposal_template_training_main',
                'history' => 'proposal_template_training_history',
                'label' => 'Training Proposal',
                'title' => 'training_title',
                'title_fields' => ['training_title'],
                'code' => 'training_code',
                'identifiers' => ['hrd_no'],
                'service_type' => ['training_title', 'service_type'],
                'search' => [
                    'training_title', 'training_code', 'hrd_no', 'introduction', 'objectives', 'modules',
                    'training_requirements', 'additional_requirements', 'additional_training_requirements',
                    'training_materials', 'lecture_medium', 'duration', 'method_theory', 'method_theory_desc',
                    'method_practical', 'method_practical_desc', 'service_type', 'remarks',
                ],
                'detail' => [
                    'training_title', 'training_code', 'hrd_no', 'introduction', 'objectives', 'modules',
                    'training_requirements', 'additional_requirements', 'additional_training_requirements',
                    'training_materials', 'lecture_medium', 'duration', 'method_theory', 'method_theory_desc',
                    'method_practical', 'method_practical_desc', 'remarks', 'proposal_language',
                    'source_template_id', 'translation_provider', 'translation_status', 'translated_at',
                    'translation_notes', 'service_type', 'is_deleted', 'status',
                ],
                'sections' => [
                    'Introduction' => 'introduction',
                    'Objectives' => 'objectives',
                    'Modules' => 'modules',
                    'Training Requirements' => 'training_requirements',
                    'Additional Requirements' => 'additional_requirements',
                    'Training Materials' => 'training_materials',
                    'Lecture Medium' => 'lecture_medium',
                    'Theory Method' => 'method_theory_desc',
                    'Practical Method' => 'method_practical_desc',
                ],
            ],
            'ih' => [
                'table' => 'proposal_template_ih',
                'history' => 'proposal_template_ih_history',
                'label' => 'Industrial Hygiene Proposal',
                'title' => 'service_title',
                'title_fields' => ['service_title'],
                'code' => 'service_code',
                'identifiers' => [],
                'service_type' => ['service_title', 'service_type'],
                'search' => [
                    'service_title', 'service_code', 'introduction', 'objectives', 'work_scope',
                    'schedule', 'reference', 'other_fields', 'service_type', 'remarks',
                ],
                'detail' => [
                    'service_title', 'service_code', 'introduction', 'objectives', 'work_scope', 'schedule',
                    'reference', 'other_fields', 'remarks', 'proposal_language', 'source_template_id',
                    'translation_provider', 'translation_status', 'translated_at', 'translation_notes',
                    'service_type', 'is_deleted', 'status',
                ],
                'sections' => [
                    'Introduction' => 'introduction',
                    'Objectives' => 'objectives',
                    'Scope of Work' => 'work_scope',
                    'Project Schedule' => 'schedule',
                    'Reference' => 'reference',
                    'Other Information' => 'other_fields',
                ],
            ],
            'manpower' => [
                'table' => 'proposal_template_manpower',
                'history' => 'proposal_template_manpower_history',
                'label' => 'Manpower Proposal',
                'title' => 'service_title',
                'title_fields' => ['service_title'],
                'code' => 'service_code',
                'identifiers' => [],
                'service_type' => ['service_title', 'service_type'],
                'search' => [
                    'service_title', 'service_code', 'introduction', 'service_deliverables',
                    'supplied_manpower_deliverables', 'custom_section', 'service_type', 'remarks',
                ],
                'detail' => [
                    'service_title', 'service_code', 'introduction', 'service_deliverables',
                    'supplied_manpower_deliverables', 'custom_section', 'remarks', 'proposal_language',
                    'source_template_id', 'translation_provider', 'translation_status', 'translated_at',
                    'translation_notes', 'service_type', 'is_deleted', 'status',
                ],
                'sections' => [
                    'Introduction' => 'introduction',
                    'Service Deliverables' => 'service_deliverables',
                    'Supplied Manpower Deliverables' => 'supplied_manpower_deliverables',
                    'Custom Section' => 'custom_section',
                ],
            ],
            'special' => [
                'table' => 'proposal_template_special',
                'history' => 'proposal_template_special_history',
                'label' => 'Special Service Proposal',
                'title' => 'title',
                'title_fields' => ['title', 'service_title'],
                'code' => 'service_code',
                'identifiers' => [],
                'service_type' => ['service_title', 'service_type'],
                'search' => [
                    'title', 'service_title', 'service_code', 'service_type', 'proposal_mode',
                    'service_summary', 'proposal_content', 'content', 'remarks',
                ],
                'detail' => [
                    'title', 'service_title', 'service_code', 'service_type', 'proposal_mode',
                    'service_summary', 'proposal_content', 'content', 'remarks', 'proposal_language',
                    'source_template_id', 'translation_provider', 'translation_status', 'translated_at',
                    'translation_notes', 'is_deleted', 'status',
                ],
                'sections' => [
                    'Internal Service Summary' => 'service_summary',
                    'Written Proposal Content' => 'proposal_content',
                ],
            ],
            default => null,
        };
    }

    private function existingColumns(string $table, array $columns): array
    {
        $available = array_flip(Schema::getColumnListing($table));

        return array_values(array_filter(
            array_unique($columns),
            static fn (string $column): bool => $column !== '' && isset($available[$column]),
        ));
    }

    private function statusFor(array $row): ?string
    {
        if (array_key_exists('status', $row) && trim((string) $row['status']) !== '') {
            return (string) $row['status'];
        }

        if (array_key_exists('is_deleted', $row)) {
            return (bool) $row['is_deleted'] ? 'deleted' : 'active';
        }

        return null;
    }

    private function firstText(array $row, array $fields): ?string
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && trim((string) ($row[$field] ?? '')) !== '') {
                return trim((string) $row[$field]);
            }
        }

        return null;
    }
}
