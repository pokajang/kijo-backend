<?php

namespace App\Services\Stats;

use App\Services\Monitoring\ManualPipelineEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait MonitoringStatsDetailHelpers
{
    private function monitoringToolsDistinctRow(string $label, array $datedKeys, array $weeks): array
    {
        $weeklySets = [];
        $weeklyItems = [];
        foreach ($weeks as $week) {
            $weeklySets[$week['key']] = [];
            $weeklyItems[$week['key']] = [];
        }

        foreach ($datedKeys as $item) {
            $weekKey = $this->monitoringResolveWeekKey($item['date'] ?? null, $weeks);
            $key = (string) ($item['key'] ?? '');
            if ($weekKey !== null && $key !== '') {
                $weeklySets[$weekKey][$key] = true;
                $contributor = $this->monitoringContributorFromEvent($item);
                if ($contributor !== null) {
                    $weeklyItems[$weekKey][$key] = $contributor;
                }
            }
        }

        $weekly = [];
        $weeklyDetails = [];
        foreach ($weeks as $week) {
            $weekly[$week['key']] = count($weeklySets[$week['key']]);
            $weeklyDetails[$week['key']] = $this->monitoringBoundDetails($weeklyItems[$week['key']]);
        }
        $segments = $this->monitoringSegmentQuantitySeed();
        $segmentSets = [
            'individual' => [],
            'special_project' => [],
            'tender' => [],
        ];
        $segmentRmValues = [
            'individual' => [],
            'special_project' => [],
            'tender' => [],
        ];
        $segmentItems = [
            'individual' => [],
            'special_project' => [],
            'tender' => [],
        ];
        $segmentRmItems = [
            'individual' => [],
            'special_project' => [],
            'tender' => [],
        ];
        $totalItems = [];

        foreach ($datedKeys as $item) {
            $segment = (string) ($item['segment'] ?? '');
            $key = (string) ($item['key'] ?? '');
            $contributor = $this->monitoringContributorFromEvent($item);
            if ($key !== '' && $contributor !== null) {
                $totalItems[$key] = $contributor;
            }
            if (isset($segmentSets[$segment]) && $key !== '') {
                $segmentSets[$segment][$key] = true;
                if ($contributor !== null) {
                    $segmentItems[$segment][$key] = $contributor;
                }
                if (array_key_exists('value', $item) && is_numeric($item['value'])) {
                    $segmentRmValues[$segment][$key] = (float) $item['value'];
                    if ($contributor !== null) {
                        $segmentRmItems[$segment][$key] = $contributor;
                    }
                }
            }
        }

        $segmentRms = [];
        $segmentDetails = [];
        foreach ($segmentSets as $segment => $keys) {
            $segments[$segment] = count($keys);
            $segmentRms[$segment] = count($segmentRmValues[$segment]) > 0
                ? array_sum($segmentRmValues[$segment])
                : 0.0;
            $detailKey = $this->monitoringSegmentDetailKey($segment);
            $segmentDetails[$detailKey] = [
                'qty' => $this->monitoringBoundDetails($segmentItems[$segment]),
                'rm' => $this->monitoringBoundDetails(
                    $segmentRmItems[$segment],
                    $segmentRms[$segment]
                ),
            ];
        }

        $total = array_sum($weekly);
        if (empty($segmentDetails['individual'])) {
            $segmentDetails['individual'] = [
                'qty' => $this->monitoringEmptyDetail(),
                'rm' => $this->monitoringBoundDetails([], 0.0),
            ];
        }
        $segmentDetails['individual']['qty'] = $this->monitoringBoundDetails($totalItems);

        return [
            'label' => $label,
            'weekly' => $weekly,
            'total' => $total,
            'individualQty' => $total,
            'individualRm' => $segmentRms['individual'] ?? 0.0,
            'specialProjectQty' => $segments['special_project'],
            'specialProjectRm' => $segmentRms['special_project'] ?? 0.0,
            'tenderQty' => $segments['tender'],
            'tenderRm' => $segmentRms['tender'] ?? 0.0,
            'details' => [
                'weekly' => $weeklyDetails,
                'total' => $this->monitoringBoundDetails($totalItems),
                'segments' => $segmentDetails,
            ],
        ];
    }

    private function monitoringToolsTotalRow(array $rows, array $weeks): array
    {
        $weekly = $this->monitoringWeekSeed($weeks);
        $weeklyItems = [];
        foreach ($weeks as $week) {
            $weeklyItems[$week['key']] = [];
        }
        $total = 0;
        $totalItems = [];
        $segmentItems = [
            'individual' => ['qty' => [], 'rm' => []],
            'specialProject' => ['qty' => [], 'rm' => []],
            'tender' => ['qty' => [], 'rm' => []],
        ];

        foreach ($rows as $row) {
            foreach ($weeks as $week) {
                $key = $week['key'];
                $weekly[$key] += (int) ($row['weekly'][$key] ?? 0);
                foreach (($row['details']['weekly'][$key]['items'] ?? []) as $item) {
                    $itemKey = $this->monitoringDetailItemKey($item, (string) ($row['label'] ?? ''));
                    $weeklyItems[$key][$itemKey] = $item;
                    $totalItems[$itemKey] = $item;
                }
            }
            $total += (int) ($row['total'] ?? 0);

            foreach (['individual', 'specialProject', 'tender'] as $segment) {
                foreach (['qty', 'rm'] as $metric) {
                    foreach (($row['details']['segments'][$segment][$metric]['items'] ?? []) as $item) {
                        $itemKey = $this->monitoringDetailItemKey($item, (string) ($row['label'] ?? ''));
                        $segmentItems[$segment][$metric][$itemKey] = $item;
                    }
                }
            }
        }

        $weeklyDetails = [];
        foreach ($weeks as $week) {
            $weeklyDetails[$week['key']] = $this->monitoringBoundDetails(
                $weeklyItems[$week['key']],
                null,
                self::MONITORING_DETAIL_LIMIT,
                (int) ($weekly[$week['key']] ?? 0)
            );
        }

        return [
            'label' => 'TOTAL',
            'weekly' => $weekly,
            'total' => $total,
            'individualQty' => $this->monitoringSumNullable($rows, 'individualQty'),
            'individualRm' => $this->monitoringSumNullableValue($rows, 'individualRm'),
            'specialProjectQty' => $this->monitoringSumNullable($rows, 'specialProjectQty'),
            'specialProjectRm' => $this->monitoringSumNullableValue($rows, 'specialProjectRm'),
            'tenderQty' => $this->monitoringSumNullable($rows, 'tenderQty'),
            'tenderRm' => $this->monitoringSumNullableValue($rows, 'tenderRm'),
            'details' => [
                'weekly' => $weeklyDetails,
                'total' => $this->monitoringBoundDetails(
                    $totalItems,
                    null,
                    self::MONITORING_DETAIL_LIMIT,
                    $total
                ),
                'segments' => [
                    'individual' => [
                        'qty' => $this->monitoringBoundDetails(
                            $segmentItems['individual']['qty'],
                            null,
                            self::MONITORING_DETAIL_LIMIT,
                            (int) ($this->monitoringSumNullable($rows, 'individualQty') ?? 0)
                        ),
                        'rm' => $this->monitoringBoundDetails(
                            $segmentItems['individual']['rm'],
                            $this->monitoringSumNullableValue($rows, 'individualRm') ?? 0.0,
                            self::MONITORING_DETAIL_LIMIT,
                            count($segmentItems['individual']['rm'])
                        ),
                    ],
                    'specialProject' => [
                        'qty' => $this->monitoringBoundDetails(
                            $segmentItems['specialProject']['qty'],
                            null,
                            self::MONITORING_DETAIL_LIMIT,
                            (int) ($this->monitoringSumNullable($rows, 'specialProjectQty') ?? 0)
                        ),
                        'rm' => $this->monitoringBoundDetails(
                            $segmentItems['specialProject']['rm'],
                            $this->monitoringSumNullableValue($rows, 'specialProjectRm') ?? 0.0,
                            self::MONITORING_DETAIL_LIMIT,
                            count($segmentItems['specialProject']['rm'])
                        ),
                    ],
                    'tender' => [
                        'qty' => $this->monitoringBoundDetails(
                            $segmentItems['tender']['qty'],
                            null,
                            self::MONITORING_DETAIL_LIMIT,
                            (int) ($this->monitoringSumNullable($rows, 'tenderQty') ?? 0)
                        ),
                        'rm' => $this->monitoringBoundDetails(
                            $segmentItems['tender']['rm'],
                            $this->monitoringSumNullableValue($rows, 'tenderRm') ?? 0.0,
                            self::MONITORING_DETAIL_LIMIT,
                            count($segmentItems['tender']['rm'])
                        ),
                    ],
                ],
            ],
        ];
    }

    private function monitoringBoundDetails(
        array $items,
        ?float $value = null,
        int $limit = self::MONITORING_DETAIL_LIMIT,
        ?int $countOverride = null
    ): array
    {
        $count = $countOverride ?? count($items);
        $details = [
            'count' => $count,
            'items' => array_slice(array_values($items), 0, $limit),
            'truncated' => $count > $limit,
        ];

        if ($value !== null) {
            $details['value'] = $value;
        }

        return $details;
    }

    private function monitoringEmptyDetail(): array
    {
        return [
            'count' => 0,
            'items' => [],
            'truncated' => false,
        ];
    }

    private function monitoringResolveWeekKey(?string $date, array $weeks): ?string
    {
        if (empty($date)) {
            return null;
        }

        foreach ($weeks as $week) {
            if ($date >= $week['start'] && $date <= $week['end']) {
                return $week['key'];
            }
        }

        return null;
    }

    private function monitoringSegmentDetailKey(string $segment): string
    {
        return match ($segment) {
            'special_project' => 'specialProject',
            'tender' => 'tender',
            default => 'individual',
        };
    }

    private function monitoringSegmentQuantitySeed(): array
    {
        return [
            'individual' => 0,
            'special_project' => 0,
            'tender' => 0,
        ];
    }

    private function monitoringDetailItemKey(array $item, string $prefix = ''): string
    {
        $parts = array_values(array_filter([
            $prefix,
            (string) ($item['eventType'] ?? ''),
            (string) ($item['sourceId'] ?? ''),
        ], static fn($part) => $part !== ''));

        return !empty($parts) ? implode('|', $parts) : md5(json_encode($item));
    }

    private function monitoringSumNullable(array $rows, string $key): ?int
    {
        $sum = 0;
        foreach ($rows as $row) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $sum += (int) $row[$key];
            }
        }

        return $sum > 0 ? $sum : null;
    }

    private function monitoringSumNullableValue(array $rows, string $key): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        foreach ($rows as $row) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $sum += (float) $row[$key];
                $hasValue = true;
            }
        }

        return $hasValue ? $sum : null;
    }

    private function monitoringWeekSeed(array $weeks): array
    {
        $seed = [];
        foreach ($weeks as $week) {
            $seed[$week['key']] = 0;
        }

        return $seed;
    }

    private function mapQuoteToMonitoringStatusLabel(string $serviceGroup, string $serviceTitle): string
    {
        $normalizedTitle = strtoupper(trim($serviceTitle));

        return match ($serviceGroup) {
            'Training' => 'TRAINING',
            'Industrial Hygiene' => 'CONSULTANCY - IHOH',
            'Manpower Supply' => 'MAN POWER',
            'Equipment Supply' => 'EQUIPMENT SUPPLY',
            'Special Service' => $this->mapSpecialServiceToMonitoringStatusLabel($normalizedTitle),
            default => 'ENGINEERING',
        };
    }

    private function monitoringFinalizeStatusDetails(array &$row): void
    {
        foreach (($row['details']['weekly'] ?? []) as $weekKey => $metrics) {
            $row['details']['weekly'][$weekKey] = [
                'qty' => $this->monitoringBoundDetails($metrics['qty'] ?? []),
                'rm' => $this->monitoringBoundDetails(
                    $metrics['rm'] ?? [],
                    (float) ($row['weekly'][$weekKey]['rm'] ?? 0)
                ),
            ];
        }

        $row['details']['total'] = [
            'qty' => $this->monitoringBoundDetails($row['details']['total']['qty'] ?? []),
            'rm' => $this->monitoringBoundDetails(
                $row['details']['total']['rm'] ?? [],
                (float) ($row['totalRm'] ?? 0)
            ),
        ];

        foreach (['individual', 'specialProject', 'tender'] as $segment) {
            $qtyKey = $segment . 'Qty';
            $rmKey = $segment . 'Rm';
            $row['details']['segments'][$segment] = [
                'qty' => $this->monitoringBoundDetails($row['details']['segments'][$segment]['qty'] ?? []),
                'rm' => $this->monitoringBoundDetails(
                    $row['details']['segments'][$segment]['rm'] ?? [],
                    (float) ($row[$rmKey] ?? 0)
                ),
            ];
        }
    }

    private function monitoringMergeStatusDetails(array &$target, array $source): void
    {
        foreach (($source['weekly'] ?? []) as $weekKey => $metrics) {
            foreach (['qty', 'rm'] as $metric) {
                foreach (($metrics[$metric] ?? []) as $item) {
                    $this->monitoringStoreDetailItem($target['weekly'][$weekKey][$metric], $item);
                }
            }
        }

        foreach (['qty', 'rm'] as $metric) {
            foreach (($source['total'][$metric] ?? []) as $item) {
                $this->monitoringStoreDetailItem($target['total'][$metric], $item);
            }
        }

        foreach (['individual', 'specialProject', 'tender'] as $segment) {
            foreach (['qty', 'rm'] as $metric) {
                foreach (($source['segments'][$segment][$metric] ?? []) as $item) {
                    $this->monitoringStoreDetailItem($target['segments'][$segment][$metric], $item);
                }
            }
        }
    }

    private function monitoringQuoteHasDirectIndividualStatusSource(string $serviceGroup): bool
    {
        return in_array($serviceGroup, [
            'Training',
            'Industrial Hygiene',
            'Manpower Supply',
            'Equipment Supply',
        ], true);
    }

    private function monitoringStatusDetailSeed(array $weeks): array
    {
        $weekly = [];
        foreach ($weeks as $week) {
            $weekly[$week['key']] = ['qty' => [], 'rm' => []];
        }

        return [
            'weekly' => $weekly,
            'total' => ['qty' => [], 'rm' => []],
            'segments' => [
                'individual' => ['qty' => [], 'rm' => []],
                'specialProject' => ['qty' => [], 'rm' => []],
                'tender' => ['qty' => [], 'rm' => []],
            ],
        ];
    }

    private function monitoringStatusLabelHasDirectIndividualSource(string $label): bool
    {
        return in_array($label, [
            'TRAINING',
            'CONSULTANCY - IHOH',
            'MAN POWER',
            'EQUIPMENT SUPPLY',
        ], true);
    }

    private function monitoringWeeklyMetricSeed(array $weeks): array
    {
        $seed = [];
        foreach ($weeks as $week) {
            $seed[$week['key']] = ['qty' => 0, 'rm' => 0.0];
        }

        return $seed;
    }

    private function mapSpecialServiceToMonitoringStatusLabel(string $normalizedTitle): string
    {
        // Workbook service rows are broader than the current CRM taxonomy.
        // Special-service quotes are bucketed heuristically until a formal
        // reporting category is added to the source data.
        if (str_contains($normalizedTitle, 'ISO')) {
            return 'CONSULTANCY -ISO';
        }

        $infrastructureKeywords = ['INFRASTRUCTURE', 'DRAIN', 'ROAD', 'BUILDING', 'STRUCTURE'];
        foreach ($infrastructureKeywords as $keyword) {
            if (str_contains($normalizedTitle, $keyword)) {
                return 'INFRASTRUCTURE';
            }
        }

        return 'ENGINEERING';
    }

    private function monitoringStoreDetailItem(array &$items, array $item): void
    {
        $key = $this->monitoringDetailItemKey($item);
        $items[$key] = $item;
    }
}
