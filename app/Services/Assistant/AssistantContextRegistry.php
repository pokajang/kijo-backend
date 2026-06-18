<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Sources\AppraisalContextProvider;
use App\Services\Assistant\Sources\AssistantHelpContextProvider;
use App\Services\Assistant\Sources\CatalogPurchaseOrderContextProvider;
use App\Services\Assistant\Sources\ClientContextProvider;
use App\Services\Assistant\Sources\ClientVendorRegistrationContextProvider;
use App\Services\Assistant\Sources\DashboardContextProvider;
use App\Services\Assistant\Sources\DebtorContextProvider;
use App\Services\Assistant\Sources\DetailRecordContextProvider;
use App\Services\Assistant\Sources\HandbookContextProvider;
use App\Services\Assistant\Sources\InvoiceContextProvider;
use App\Services\Assistant\Sources\Jd14ContextProvider;
use App\Services\Assistant\Sources\KnowledgeArticleContextProvider;
use App\Services\Assistant\Sources\LeaveContextProvider;
use App\Services\Assistant\Sources\LegalComplianceContextProvider;
use App\Services\Assistant\Sources\MeetingContextProvider;
use App\Services\Assistant\Sources\ProcedureContextProvider;
use App\Services\Assistant\Sources\ProjectContextProvider;
use App\Services\Assistant\Sources\ProposalTemplateContextProvider;
use App\Services\Assistant\Sources\QuoteRecordContextProvider;
use App\Services\Assistant\Sources\SalesInquiryContextProvider;
use App\Services\Assistant\Sources\StaffDirectoryContextProvider;
use App\Services\Assistant\Sources\SystemFeedbackContextProvider;
use App\Services\Assistant\Sources\TaskContextProvider;
use App\Services\Assistant\Sources\UserTraceContextProvider;
use App\Services\Assistant\Sources\VendorContextProvider;
use App\Services\Assistant\Sources\WhatsNewContextProvider;
use Illuminate\Http\Request;

class AssistantContextRegistry
{
    private const MAX_SOURCES = 4;

    public function __construct(
        private readonly AssistantFeedbackMemory $feedbackMemory,
        private readonly AssistantContextQualityService $contextQuality,
        private readonly AssistantQuestionIntentResolver $intentResolver,
        private readonly AssistantSourceIntentRanker $intentRanker,
    ) {}

    /**
     * @return AssistantContextProvider[]
     */
    public function providers(): array
    {
        return [
            app(AssistantHelpContextProvider::class),
            app(UserTraceContextProvider::class),
            app(KnowledgeArticleContextProvider::class),
            app(HandbookContextProvider::class),
            app(DashboardContextProvider::class),
            app(DetailRecordContextProvider::class),
            app(ProjectContextProvider::class),
            app(ClientContextProvider::class),
            app(VendorContextProvider::class),
            app(InvoiceContextProvider::class),
            app(DebtorContextProvider::class),
            app(ClientVendorRegistrationContextProvider::class),
            app(QuoteRecordContextProvider::class),
            app(SalesInquiryContextProvider::class),
            app(LeaveContextProvider::class),
            app(TaskContextProvider::class),
            app(StaffDirectoryContextProvider::class),
            app(LegalComplianceContextProvider::class),
            app(ProposalTemplateContextProvider::class),
            app(Jd14ContextProvider::class),
            app(SystemFeedbackContextProvider::class),
            app(CatalogPurchaseOrderContextProvider::class),
            app(MeetingContextProvider::class),
            app(ProcedureContextProvider::class),
            app(AppraisalContextProvider::class),
            app(WhatsNewContextProvider::class),
        ];
    }

    public function retrieve(
        string $question,
        string $currentRoute,
        Request $request,
        ?AssistantRetrievalPlan $plan = null,
    ): AssistantContextResult
    {
        $sources = [];
        $providerKeys = [];
        $hasLive = false;
        $freshnessLabels = [];

        foreach ($this->providers() as $provider) {
            $providerKey = $provider->key();
            $planned = $plan !== null
                && $provider instanceof PlannedAssistantContextProvider
                && $provider->supportsPlan($plan, $question, $currentRoute, $request);

            if (! $planned && ! $provider->supports($question, $currentRoute, $request)) {
                AssistantDiagnosticsRecorder::recordProviderSkip(
                    $providerKey,
                    $plan !== null && $provider instanceof PlannedAssistantContextProvider ? 'plan_not_supported' : 'unsupported',
                );
                continue;
            }

            $result = $planned
                ? $provider->retrievePlanned($plan, $question, $currentRoute, $request)
                : $provider->retrieve($question, $currentRoute, $request);
            AssistantDiagnosticsRecorder::recordProviderRun($providerKey, $planned, $result);
            if (isset($result->metadata['direct_answer'])) {
                return $result;
            }

            $providerKeys = array_values(array_unique(array_merge($providerKeys, $result->providerKeys)));
            $sources = array_merge($sources, $result->sources);
            if (in_array($result->answerMode, ['live', 'mixed'], true)) {
                $hasLive = true;
            }
            if ($result->freshnessLabel) {
                $freshnessLabels[] = $result->freshnessLabel;
            }
        }

        $intent = $this->intentResolver->resolve($question, $currentRoute, $plan);
        AssistantDiagnosticsRecorder::recordScoreStage('before_feedback_memory', $sources);
        $sources = $this->feedbackMemory->applySourceScores($question, $currentRoute, $request, $sources);
        AssistantDiagnosticsRecorder::recordScoreStage('after_feedback_memory', $sources);
        $sources = $this->intentRanker->rank($sources, $intent, $question, $currentRoute);
        AssistantDiagnosticsRecorder::recordScoreStage('after_intent_ranking', $sources);

        $rankedSources = $sources;
        $sources = collect($rankedSources)
            ->filter(fn (array $source): bool => ($source['score'] ?? 0) > 0)
            ->sortByDesc(fn (array $source): int|float => $source['score'] ?? 0)
            ->unique(fn (array $source): string => (string) ($source['slug'] ?? $source['ref'] ?? $source['title'] ?? ''))
            ->take(self::MAX_SOURCES)
            ->values()
            ->all();
        $selectedKeys = array_flip(array_map(
            static fn (array $source): string => (string) ($source['slug'] ?? $source['ref'] ?? $source['title'] ?? ''),
            $sources,
        ));
        AssistantDiagnosticsRecorder::recordSelectedSources($sources);
        AssistantDiagnosticsRecorder::recordSuppressedSources(array_values(array_map(
            static function (array $source) use ($selectedKeys): array {
                $key = (string) ($source['slug'] ?? $source['ref'] ?? $source['title'] ?? '');
                if (($source['score'] ?? 0) <= 0) {
                    $source['suppression_reason'] = 'non_positive_score';
                } elseif (! isset($selectedKeys[$key])) {
                    $source['suppression_reason'] = 'not_in_top_ranked_sources';
                }

                return $source;
            },
            array_filter(
                $rankedSources,
                static fn (array $source): bool => ($source['score'] ?? 0) <= 0
                    || ! isset($selectedKeys[(string) ($source['slug'] ?? $source['ref'] ?? $source['title'] ?? '')]),
            ),
        )));

        if ($sources === []) {
            return new AssistantContextResult([], 'static', null, $providerKeys, 'insufficient', ['source']);
        }

        $liveTypes = [
            'live_metric' => true,
            'live_entity' => true,
            'project' => true,
            'client' => true,
            'vendor' => true,
            'invoice' => true,
            'debtor' => true,
            'delivery_order' => true,
            'vendor_registration' => true,
            'quote_record' => true,
            'sales_inquiry' => true,
            'leave' => true,
            'salary' => true,
            'task' => true,
            'staff' => true,
            'legal_compliance' => true,
            'proposal_template' => true,
            'jd14' => true,
            'system_feedback' => true,
            'catalog' => true,
            'purchase_order' => true,
            'meeting' => true,
            'procedure' => true,
            'appraisal' => true,
            'whats_new' => true,
            'user_trace' => true,
        ];
        $hasLive = count(array_filter(
            $sources,
            fn (array $source): bool => isset($liveTypes[(string) ($source['source_type'] ?? '')]),
        )) > 0;
        $hasStatic = count(array_filter(
            $sources,
            fn (array $source): bool => ! isset($liveTypes[(string) ($source['source_type'] ?? '')]),
        )) > 0;

        $quality = $this->contextQuality->summarize($sources);

        return new AssistantContextResult(
            $sources,
            $hasLive ? ($hasStatic ? 'mixed' : 'live') : 'static',
            $hasLive ? ($freshnessLabels[0] ?? null) : null,
            $providerKeys,
            $quality['context_quality'],
            $quality['missing_fields'],
            $quality['metadata'],
        );
    }
}
