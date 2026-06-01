<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextProvider;
use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantText;
use App\Services\Clients\ClientRoiReportService;
use App\Services\Stats\StatsService;
use App\Services\Stats\WorkloadDashboardStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class DashboardContextProvider implements AssistantContextProvider
{
    public function __construct(
        private readonly AssistantText $text,
        private readonly StatsService $stats,
        private readonly WorkloadDashboardStatsService $workloadStats,
        private readonly ClientRoiReportService $clientRoi,
    ) {}

    public function key(): string
    {
        return 'dashboard';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        $tokenList = $this->text->tokens($question);
        $tokens = array_flip($tokenList);
        if (str_starts_with(trim($currentRoute), '/dashboard') && $this->hasRouteContextIntent($question, $tokens)) {
            return true;
        }

        $hasActionIntent = collect($tokenList)->contains(fn (string $token): bool => $this->text->isActionToken($token));
        $hasMetricIntent = collect([
            'dashboard', 'sale', 'sales', 'award', 'awarded', 'conversion', 'finance', 'financial',
            'invoice', 'debtor', 'debtors', 'received', 'revenue', 'monitoring', 'pipeline', 'workload',
            'score', 'returning', 'roi', 'number', 'metric',
        ])->contains(fn (string $token): bool => isset($tokens[$token]));

        if ($hasActionIntent && ! $hasMetricIntent) {
            return false;
        }

        foreach ([
            'dashboard', 'sale', 'sales', 'crm', 'quote', 'quotation', 'award', 'awarded', 'conversion',
            'finance', 'financial', 'invoice', 'debtor', 'debtors', 'received', 'revenue', 'monitoring',
            'pipeline', 'workload', 'score', 'task', 'returning', 'roi',
        ] as $token) {
            if (isset($tokens[$token])) {
                return true;
            }
        }

        return false;
    }

    private function hasRouteContextIntent(string $question, array $tokens): bool
    {
        $normalized = strtolower($question);
        if (str_contains($normalized, 'explain this page') || str_contains($normalized, 'explain current page')) {
            return true;
        }

        foreach ([
            'dashboard', 'page', 'screen', 'module', 'metric', 'number', 'summary', 'trend', 'chart',
            'table', 'current', 'period', 'month', 'year', 'explain', 'terangkan', 'halaman', 'skrin',
            'modul', 'ringkasan', 'jualan', 'kewangan',
        ] as $token) {
            if (isset($tokens[$token]) || str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $tokens = array_flip($this->text->tokens($question.' '.$currentRoute));
        $range = $this->dateRange();
        $sourceCandidates = [];

        if ($this->wantsSales($tokens, $currentRoute)) {
            $sourceCandidates[] = $this->salesSource($range, $request);
        }
        if ($this->wantsCrm($tokens, $currentRoute)) {
            $sourceCandidates[] = $this->crmSource($range, $request);
        }
        if ($this->wantsFinancial($tokens, $currentRoute)) {
            $sourceCandidates[] = $this->financialSource($range, $request);
        }
        if ($this->wantsMonitoring($tokens, $currentRoute)) {
            $sourceCandidates[] = $this->monitoringSource($range, $request);
        }
        if ($this->wantsWorkload($tokens, $currentRoute)) {
            $sourceCandidates[] = $this->workloadSource($range, $request);
        }
        if ($this->wantsClientRoi($tokens)) {
            $sourceCandidates[] = $this->clientRoiSource($range);
        }

        if ($sourceCandidates === [] && str_starts_with(trim($currentRoute), '/dashboard')) {
            $sourceCandidates[] = $this->salesSource($range, $request);
            $sourceCandidates[] = $this->crmSource($range, $request);
            $sourceCandidates[] = $this->financialSource($range, $request);
        }

        $sources = array_values(array_filter($sourceCandidates));

        return new AssistantContextResult(
            $sources,
            $sources === [] ? 'static' : 'live',
            $sources === [] ? null : $this->freshnessLabel(),
            [$this->key()],
        );
    }

    private function wantsSales(array $tokens, string $currentRoute): bool
    {
        return str_contains($currentRoute, '/dashboard/sales')
            || isset($tokens['sale'])
            || isset($tokens['sales'])
            || isset($tokens['award'])
            || isset($tokens['awarded'])
            || isset($tokens['revenue']);
    }

    private function wantsCrm(array $tokens, string $currentRoute): bool
    {
        return str_contains($currentRoute, '/dashboard/crm')
            || isset($tokens['crm'])
            || isset($tokens['quote'])
            || isset($tokens['quotation'])
            || isset($tokens['conversion']);
    }

    private function wantsFinancial(array $tokens, string $currentRoute): bool
    {
        return str_contains($currentRoute, '/dashboard/financial')
            || isset($tokens['finance'])
            || isset($tokens['financial'])
            || isset($tokens['invoice'])
            || isset($tokens['debtor'])
            || isset($tokens['debtors'])
            || isset($tokens['received']);
    }

    private function wantsMonitoring(array $tokens, string $currentRoute): bool
    {
        return str_contains($currentRoute, '/dashboard/monitoring')
            || isset($tokens['monitoring'])
            || isset($tokens['pipeline']);
    }

    private function wantsWorkload(array $tokens, string $currentRoute): bool
    {
        return str_contains($currentRoute, '/dashboard/workload')
            || isset($tokens['workload'])
            || isset($tokens['score'])
            || isset($tokens['task']);
    }

    private function wantsClientRoi(array $tokens): bool
    {
        return isset($tokens['returning'])
            || isset($tokens['roi']);
    }

    private function salesSource(array $range, Request $baseRequest): ?array
    {
        $request = $this->statsRequest($range, $baseRequest);
        $monthlySales = $this->responseData(fn () => $this->stats->monthlySales($request));
        $byPerson = $this->responseData(fn () => $this->stats->awardedValueByPerson($request));
        $byService = $this->responseData(fn () => $this->stats->awardedValueByService($request));

        return $this->metricSource(
            'dashboard:sales',
            'Sales dashboard metrics',
            '/dashboard/sales',
            [
                'period' => $range,
                'monthly_sales' => array_slice($monthlySales['monthlySales'] ?? [], -6),
                'top_awarded_people' => array_slice($byPerson['awardValueByPerson'] ?? [], 0, 5),
                'top_awarded_services' => array_slice($byService['awardValueByService'] ?? [], 0, 5),
            ],
            360,
        );
    }

    private function crmSource(array $range, Request $baseRequest): ?array
    {
        $request = $this->statsRequest($range, $baseRequest);
        $quoteCount = $this->responseData(fn () => $this->stats->quoteCountByPerson($request));
        $quoteValue = $this->responseData(fn () => $this->stats->quoteValueByService($request));
        $conversion = $this->responseData(fn () => $this->stats->conversionRateBySource($request));

        return $this->metricSource(
            'dashboard:crm',
            'CRM dashboard metrics',
            '/dashboard/crm',
            [
                'period' => $range,
                'quote_count_by_person' => array_slice($quoteCount['quoteCountByPerson'] ?? [], 0, 5),
                'quote_value_by_service' => array_slice($quoteValue['quoteValueByService'] ?? [], 0, 5),
                'conversion_by_source' => array_slice($conversion['conversionRateBySource'] ?? [], 0, 5),
            ],
            350,
        );
    }

    private function financialSource(array $range, Request $baseRequest): ?array
    {
        $request = $this->statsRequest($range, $baseRequest);
        $income = $this->responseData(fn () => $this->stats->monthlyIncomeStatement($request));
        $trend = $this->responseData(fn () => $this->stats->monthlyInvoicedReceivedTrend($request));
        $debtors = $this->responseData(fn () => $this->stats->allDebtors($request));

        return $this->metricSource(
            'dashboard:financial',
            'Financial dashboard metrics',
            '/dashboard/financial',
            [
                'period' => $range,
                'income_statement' => $income,
                'recent_invoiced_received_trend' => array_slice($trend['monthlyInvoicedReceivedTrend'] ?? [], -6),
                'top_debtors' => array_slice($debtors['debtors'] ?? $debtors['rows'] ?? [], 0, 5),
            ],
            350,
        );
    }

    private function monitoringSource(array $range, Request $baseRequest): ?array
    {
        $request = $this->statsRequest($range, $baseRequest);
        $status = $this->responseData(fn () => $this->stats->monitoringPipelineStatus($request));
        $tools = $this->responseData(fn () => $this->stats->monitoringPipelineTools($request));
        $trends = $this->responseData(fn () => $this->stats->monitoringTrends($request));

        return $this->metricSource(
            'dashboard:monitoring',
            'Monitoring dashboard metrics',
            '/dashboard/monitoring',
            [
                'period' => $range,
                'pipeline_status' => $status,
                'pipeline_tools' => $tools,
                'trends' => array_slice($trends['trends'] ?? $trends['monitoringTrends'] ?? [], -6),
            ],
            340,
        );
    }

    private function workloadSource(array $range, Request $baseRequest): ?array
    {
        $request = $this->statsRequest($range, $baseRequest);

        try {
            $payload = $this->workloadStats->workloadPayload($request);
        } catch (Throwable $exception) {
            report($exception);
            $payload = [];
        }

        $staff = array_slice($payload['staff'] ?? [], 0, 5);
        $summary = array_map(fn (array $row): array => [
            'staff' => $row['staffLabel'] ?? $row['staffName'] ?? null,
            'score' => $row['score'] ?? null,
            'active_tasks' => $row['activeTasks'] ?? null,
            'overdue_tasks' => $row['overdueTasks'] ?? null,
            'due_soon_tasks' => $row['dueSoonTasks'] ?? null,
        ], $staff);

        return $this->metricSource(
            'dashboard:workload',
            'Workload dashboard metrics',
            '/dashboard/workload',
            [
                'period' => $range,
                'as_of_date' => $payload['asOfDate'] ?? $range['end_date'],
                'top_workload_staff' => $summary,
            ],
            340,
        );
    }

    private function clientRoiSource(array $range): ?array
    {
        try {
            $rows = $this->clientRoi->reportRows($range['start_date'], $range['end_date']);
        } catch (Throwable $exception) {
            report($exception);
            $rows = [];
        }

        return $this->metricSource(
            'live_metric:client-roi',
            'Client ROI and returning client metrics',
            '/client/roi',
            [
                'period' => $range,
                'top_clients_by_awarded_value' => array_slice($rows, 0, 5),
                'ranking_note' => 'Rows are sorted by awarded value, then actual profit.',
            ],
            330,
        );
    }

    private function metricSource(string $slug, string $title, string $route, array $payload, int $score): ?array
    {
        $excerpt = $this->text->excerpt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 2500);
        if ($excerpt === '') {
            return null;
        }

        return [
            'id' => crc32($slug),
            'type' => 'live_metric',
            'source_type' => 'live_metric',
            'title' => $title,
            'slug' => $slug,
            'summary' => $this->freshnessLabel(),
            'category' => 'Dashboard',
            'related_route' => $route,
            'excerpt' => $excerpt,
            'fingerprint' => sha1($slug.'|'.$excerpt),
            'freshness_label' => $this->freshnessLabel(),
            'score' => $score,
        ];
    }

    private function statsRequest(array $range, Request $baseRequest): Request
    {
        $request = Request::create('/assistant/dashboard-context', 'GET', [
            'period' => 'currentYear',
            'start_date' => $range['start_date'],
            'end_date' => $range['end_date'],
            'start' => $range['start_date'],
            'end' => $range['end_date'],
        ]);
        $request->setLaravelSession($baseRequest->session());
        $request->headers->replace($baseRequest->headers->all());

        return $request;
    }

    private function responseData(callable $callback): array
    {
        try {
            $response = $callback();
            if ($response instanceof JsonResponse) {
                $data = $response->getData(true);

                return is_array($data) && ($data['status'] ?? 'success') !== 'error' ? $data : [];
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return [];
    }

    private function dateRange(): array
    {
        return [
            'start_date' => Carbon::now()->startOfYear()->toDateString(),
            'end_date' => Carbon::now()->toDateString(),
        ];
    }

    private function freshnessLabel(): string
    {
        return 'As of '.Carbon::now()->format('d M Y, H:i');
    }
}
