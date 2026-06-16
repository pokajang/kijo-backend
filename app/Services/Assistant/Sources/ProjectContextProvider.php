<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextResult;
use App\Services\Assistant\AssistantContextSanitizer;
use App\Services\Assistant\AssistantText;
use App\Services\Assistant\ModuleEntityResolver;
use App\Services\Projects\ProjectFinanceService;
use App\Services\Projects\ProjectListService;
use Illuminate\Http\Request;

class ProjectContextProvider extends ModuleContextProvider
{
    private const ROUTE_PATTERNS = [
        '~/(?:project|projects)(?:/[^/]+)?/(\d+)(?:/|$)~i',
    ];

    public function __construct(
        AssistantText $text,
        private readonly ProjectListService $projects,
        private readonly ProjectFinanceService $finance,
        private readonly ModuleEntityResolver $resolver,
        private readonly AssistantContextSanitizer $sanitizer,
    ) {
        parent::__construct($text);
    }

    public function key(): string
    {
        return 'project';
    }

    public function supports(string $question, string $currentRoute, Request $request): bool
    {
        return str_contains(strtolower($currentRoute), '/project')
            || $this->hasToken($question, [
                'project', 'projek', 'progress', 'collaborator', 'collaborators',
            ]);
    }

    public function retrieve(string $question, string $currentRoute, Request $request): AssistantContextResult
    {
        $projectRows = $this->projectOptions($request);
        if ($projectRows === []) {
            return AssistantContextResult::empty($this->key());
        }

        $resolved = $this->resolver->resolve(
            $question,
            $currentRoute,
            $projectRows,
            'id',
            'projectName',
            ['projectName', 'clientName', 'projectType', 'status'],
            self::ROUTE_PATTERNS,
        );

        if ($resolved['status'] === 'ambiguous') {
            return $this->resultFromSource($this->ambiguousSource($resolved['matches']));
        }

        if ($resolved['status'] === 'resolved') {
            return $this->resultFromSource($this->projectSource((int) $resolved['row']['id'], $question, $request));
        }

        $ranked = $this->resolver->rankedMatches(
            $question,
            $projectRows,
            'projectName',
            ['projectName', 'clientName', 'projectType', 'status'],
        );
        $matches = array_column(array_slice($ranked, 0, 5), 'row');
        if ($matches === [] && ! $this->hasListIntent($question) && ! str_contains(strtolower($currentRoute), '/project')) {
            return AssistantContextResult::empty($this->key());
        }

        return $this->resultFromSource($this->projectListSource($matches ?: array_slice($projectRows, 0, 5)));
    }

    private function projectOptions(Request $request): array
    {
        $payload = $this->responseData(fn () => $this->projects->options(
            $this->clonedRequest($request, '/assistant/projects/options', ['status' => 'all']),
        ));

        return array_map(fn ($row): array => (array) $row, $payload['data'] ?? []);
    }

    private function projectSource(int $projectId, string $question, Request $request): ?array
    {
        $payload = $this->responseData(fn () => $this->projects->show(
            $this->clonedRequest($request, "/assistant/projects/{$projectId}"),
            $projectId,
        ));
        $project = is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if (! $project) {
            return null;
        }

        $context = $this->sanitizer->keep($project, [
            'id',
            'project_name',
            'project_type',
            'po_loa_number',
            'quote_value',
            'current_project_value',
            'resolved_project_value',
            'service_start_date',
            'service_end_date',
            'description',
            'status',
            'award_date',
            'client_name',
            'client_payment_terms_days',
            'client_payment_terms_source',
            'progress_updates',
            'assigned_staff',
            'vendors',
            'closing_details',
        ]);
        $context['progress_updates'] = $this->sanitizer->rows($project['progress_updates'] ?? [], [
            'progress_date',
            'progress_text',
            'updated_on',
        ], 3);
        $context['assigned_staff'] = $this->sanitizer->rows($project['assigned_staff'] ?? [], [
            'full_name',
            'name_code',
            'project_role',
        ], 8);
        $context['vendors'] = $this->sanitizer->rows($project['vendors'] ?? [], [
            'vendor_id',
            'vendor_name',
            'award_value',
            'position',
            'services_description',
            'payment_terms',
        ], 5);

        if ($this->hasToken($question, ['finance', 'financial', 'payment', 'payments', 'expense', 'expenses', 'outstanding', 'cost'])) {
            $financePayload = $this->responseData(fn () => $this->finance->financeData(
                $this->clonedRequest($request, '/assistant/projects/finance', ['project_id' => $projectId]),
            ));
            $context['finance'] = [
                'outstanding_vendor_payments' => $financePayload['outstanding'] ?? null,
                'recent_payments' => $this->sanitizer->rows($financePayload['history'] ?? [], [
                    'vendor_name',
                    'payment_context',
                    'amount',
                    'method',
                    'status',
                    'created_at',
                    'date_approved',
                    'payment_type',
                ], 5),
                'recent_expenses' => $this->sanitizer->rows($financePayload['expenses'] ?? [], [
                    'date',
                    'amount',
                    'remarks',
                    'created_at',
                    'created_by_name_code',
                ], 5),
            ];
        }

        return $this->source(
            "project:{$projectId}",
            'project',
            (string) ($project['project_name'] ?? "Project #{$projectId}"),
            "/project/manage/{$projectId}",
            ['project' => $context],
            460,
            'Projects',
        );
    }

    private function projectListSource(array $projects): ?array
    {
        $rows = $this->sanitizer->rows($projects, [
            'id',
            'projectName',
            'clientName',
            'projectType',
            'status',
            'startDate',
            'endDate',
            'quoteValue',
            'currentProjectValue',
            'resolvedProjectValue',
        ], 5);

        return $this->source(
            'project:list:'.substr(sha1(json_encode($rows)), 0, 12),
            'project',
            'Project matches',
            '/project/manage',
            [
                'note' => 'Multiple project records may be relevant. Ask with the exact project name for a specific status or finance answer.',
                'projects' => $rows,
            ],
            300,
            'Projects',
        );
    }

    private function ambiguousSource(array $matches): ?array
    {
        $rows = $this->sanitizer->rows($matches, [
            'id',
            'projectName',
            'clientName',
            'projectType',
            'status',
        ], 5);

        return $this->source(
            'project:ambiguous:'.substr(sha1(json_encode($rows)), 0, 12),
            'live_entity',
            'Ambiguous project matches',
            '/project/manage',
            [
                'note' => 'The question matched multiple projects. Ask again with the exact project name or project ID.',
                'matches' => $rows,
            ],
            360,
            'Projects',
        );
    }

    private function resultFromSource(?array $source): AssistantContextResult
    {
        return new AssistantContextResult(
            $source ? [$source] : [],
            $source ? 'live' : 'static',
            $source ? $this->freshnessLabel() : null,
            [$this->key()],
        );
    }
}
