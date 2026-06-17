<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantContextRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Storage;

class AssistantEvaluate extends Command
{
    protected $signature = 'assistant:evaluate {--fixture=backend-laravel/tests/Fixtures/assistant_eval_cases.json} {--dry-run}';

    protected $description = 'Evaluate deterministic Learn Kijo assistant retrieval against fixture cases.';

    public function handle(AssistantContextRegistry $registry): int
    {
        $fixture = (string) $this->option('fixture');
        $path = $this->resolvePath($fixture);
        if (! is_file($path)) {
            $this->error("Fixture not found: {$fixture}");

            return self::FAILURE;
        }

        $cases = json_decode((string) file_get_contents($path), true);
        if (! is_array($cases)) {
            $this->error('Fixture must contain a JSON array.');

            return self::FAILURE;
        }

        $results = [];
        foreach ($cases as $index => $case) {
            if (! is_array($case)) {
                continue;
            }
            $result = $this->evaluateCase($registry, $case, $index + 1);
            $results[] = $result;
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        $failed = collect($results)->where('passed', false)->count();
        $reportPath = 'assistant-eval/assistant-eval-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($reportPath, json_encode([
            'fixture' => $fixture,
            'dry_run' => (bool) $this->option('dry-run'),
            'passed' => count($results) - $failed,
            'failed' => $failed,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->info("Assistant evaluation report written to storage/app/{$reportPath}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function evaluateCase(AssistantContextRegistry $registry, array $case, int $number): array
    {
        $question = (string) ($case['question'] ?? '');
        $route = (string) ($case['current_route'] ?? '');
        $role = (string) ($case['role'] ?? 'staff');
        $context = $registry->retrieve($question, $route, $this->requestForRole($role));
        $sourceTypes = array_values(array_unique(array_filter(array_map(
            static fn (array $source): string => (string) ($source['source_type'] ?? $source['type'] ?? ''),
            $context->sources,
        ))));
        $providerKeys = array_values(array_unique(array_filter($context->providerKeys)));
        $answerText = strtolower(implode(' ', array_map(
            static fn (array $source): string => (string) ($source['title'] ?? '').' '.(string) ($source['excerpt'] ?? ''),
            $context->sources,
        )));

        $failures = [];
        foreach ((array) ($case['expected_provider_keys'] ?? []) as $provider) {
            if (! in_array($provider, $providerKeys, true)) {
                $failures[] = "missing provider {$provider}";
            }
        }
        foreach ((array) ($case['expected_source_types'] ?? []) as $type) {
            if (! in_array($type, $sourceTypes, true)) {
                $failures[] = "missing source type {$type}";
            }
        }
        foreach ((array) ($case['forbidden_source_types'] ?? []) as $type) {
            if (in_array($type, $sourceTypes, true)) {
                $failures[] = "forbidden source type {$type}";
            }
        }
        foreach ((array) ($case['expected_answer_contains'] ?? []) as $needle) {
            if (! str_contains($answerText, strtolower((string) $needle))) {
                $failures[] = "missing answer/source text {$needle}";
            }
        }
        foreach ((array) ($case['forbidden_answer_contains'] ?? []) as $needle) {
            if (str_contains($answerText, strtolower((string) $needle))) {
                $failures[] = "forbidden answer/source text {$needle}";
            }
        }

        return [
            'case' => $number,
            'question' => $question,
            'route' => $route,
            'role' => $role,
            'passed' => $failures === [],
            'failures' => $failures,
            'provider_keys' => $providerKeys,
            'source_types' => $sourceTypes,
            'source_count' => count($context->sources),
            'answer_mode' => $context->answerMode,
            'context_quality' => $context->contextQuality,
        ];
    }

    private function requestForRole(string $role): Request
    {
        $roleLabels = match (strtolower($role)) {
            'system-admin', 'system admin', 'admin' => ['System Admin'],
            'manager' => ['Manager'],
            'hr' => ['HR'],
            'finance' => ['Finance'],
            'sales' => ['Sales'],
            default => ['Staff'],
        };

        $request = Request::create('/assistant/evaluate', 'GET');
        $session = new Store('assistant-evaluate', new ArraySessionHandler(3600));
        $session->start();
        $session->put('user_id', 1);
        $session->put('staff_id', 7);
        $session->put('name_code', 'EVAL');
        $session->put('roles', $roleLabels);
        $request->setLaravelSession($session);

        return $request;
    }

    private function resolvePath(string $fixture): string
    {
        if (is_file($fixture)) {
            return $fixture;
        }

        return base_path('../'.ltrim($fixture, '/\\'));
    }
}
