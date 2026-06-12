<?php

namespace App\Services\Quotes\Records;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuoteRecordConfig
{
    public function quoteConfig(string $service): ?array
    {
        return match ($service) {
            'training' => ['table' => 'quotes_training', 'project_type' => 'Training', 'child_table' => null],
            'ih' => ['table' => 'quotes_ih', 'project_type' => 'Industrial Hygiene', 'child_table' => 'quotes_ih_items'],
            'manpower' => ['table' => 'quotes_manpower', 'project_type' => 'Manpower Supply', 'child_table' => null],
            'special' => ['table' => 'quotes_special', 'project_type' => 'Special Service', 'child_table' => 'quotes_special_items'],
            'equipment' => ['table' => 'quotes_equipment', 'project_type' => 'Equipment Supply', 'child_table' => 'quotes_equipment_items'],
            default => null,
        };
    }

    public function projectTypeLike(string $service): string|array
    {
        return match ($service) {
            'training' => '%training%',
            'ih' => ['%industrial%', '%ih%'],
            'manpower' => '%manpower%',
            'special' => '%special%',
            'equipment' => '%equipment%',
            default => '%',
        };
    }

    public function normalizeServiceKey(string $service): string
    {
        $service = strtolower(trim($service));
        return match ($service) {
            'equipment-tab', 'equipment supply', 'equipment' => 'equipment',
            'manpower-tab', 'manpower supply', 'manpower' => 'manpower',
            'ih-tab', 'industrial hygiene', 'ih' => 'ih',
            'special-tab', 'special service', 'special' => 'special',
            'training-tab', 'training' => 'training',
            default => $service,
        };
    }

    public function normalizeServiceType(string $serviceType): ?string
    {
        $normalized = $this->normalizeServiceKey($serviceType);
        return match ($normalized) {
            'equipment' => 'Equipment Supply',
            'manpower' => 'Manpower Supply',
            'ih' => 'Industrial Hygiene',
            'special' => 'Special Service',
            'training' => 'Training',
            default => null,
        };
    }

    public function normalizeProposalLanguage(mixed $language): string
    {
        $value = strtolower(trim((string) $language));
        return match ($value) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    public function linkedProjectsBase(string $service)
    {
        $query = DB::table('projects_main');
        $like = $this->projectTypeLike($service);

        if ($this->hasColumn('projects_main', 'quote_type')) {
            $query->where(function ($q) use ($service, $like) {
                $q->where('quote_type', $service)
                    ->orWhere(function ($q2) use ($like) {
                        $q2->where(function ($q3) {
                            $q3->whereNull('quote_type')->orWhere('quote_type', '');
                        });
                        if (is_array($like)) {
                            $q2->where(function ($q4) use ($like) {
                                foreach ($like as $needle) {
                                    $q4->orWhereRaw('LOWER(project_type) LIKE ?', [$needle]);
                                }
                            });
                        } else {
                            $q2->whereRaw('LOWER(project_type) LIKE ?', [$like]);
                        }
                    });
            });
        } else {
            if (is_array($like)) {
                $query->where(function ($q) use ($like) {
                    foreach ($like as $needle) {
                        $q->orWhereRaw('LOWER(project_type) LIKE ?', [$needle]);
                    }
                });
            } else {
                $query->whereRaw('LOWER(project_type) LIKE ?', [$like]);
            }
        }

        return $query;
    }

    public function filterColumns(string $table, array $payload): array
    {
        $cols = $this->tableColumns($table);
        if (empty($cols)) {
            return [];
        }
        return array_intersect_key($payload, array_flip($cols));
    }

    public function tableColumns(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }
        try {
            return Schema::getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }
    }

    public function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
