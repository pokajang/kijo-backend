<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantContextRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

class AssistantSmoke extends Command
{
    protected $signature = 'assistant:smoke {--role=system-admin} {--dry-run}';

    protected $description = 'Run read-only Learn Kijo assistant source coverage smoke checks.';

    public function handle(AssistantContextRegistry $registry): int
    {
        $role = strtolower((string) $this->option('role'));
        $request = $this->requestForRole($role);
        $scenarios = $this->scenarios();
        $this->info('Learn Kijo assistant smoke'.($this->option('dry-run') ? ' (dry-run)' : '')." for role: {$role}");

        foreach ($scenarios as $scenario) {
            $context = $registry->retrieve($scenario['question'], $scenario['route'], $request);
            $sourceTypes = array_values(array_unique(array_filter(array_map(
                static fn (array $source): string => (string) ($source['source_type'] ?? $source['type'] ?? ''),
                $context->sources,
            ))));

            $restrictedWarning = $this->restrictedWarning($role, $sourceTypes);
            $this->line(json_encode([
                'scenario' => $scenario['name'],
                'question' => $scenario['question'],
                'route' => $scenario['route'],
                'answer_mode' => $context->answerMode,
                'context_quality' => $context->contextQuality,
                'provider_keys' => $context->providerKeys,
                'source_types' => $sourceTypes,
                'source_count' => count($context->sources),
                'low_confidence_or_no_source' => $context->sources === [] || $context->contextQuality !== 'complete',
                'restricted_data_warning' => $restrictedWarning,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function requestForRole(string $role): Request
    {
        $roleLabels = match ($role) {
            'manager' => ['Manager'],
            'hr' => ['HR'],
            'finance' => ['Finance'],
            'sales', 'crm' => ['Sales'],
            'staff' => ['Staff'],
            default => ['System Admin'],
        };

        $request = Request::create('/assistant/smoke', 'GET');
        $session = new Store('assistant-smoke', new ArraySessionHandler(3600));
        $session->start();
        $session->put('user_id', 1);
        $session->put('staff_id', 1);
        $session->put('name_code', strtoupper($roleLabels[0] ?? 'ADMIN'));
        $session->put('roles', $roleLabels);
        $request->setLaravelSession($session);

        return $request;
    }

    private function scenarios(): array
    {
        return [
            ['name' => 'Knowledge workflow', 'question' => 'How do I create a quotation?', 'route' => '/crm/quotes'],
            ['name' => 'Handbook policy', 'question' => 'What is the leave policy?', 'route' => '/handbook'],
            ['name' => 'Dashboard metric', 'question' => 'What is our sales dashboard now?', 'route' => '/dashboard/sales'],
            ['name' => 'Project status', 'question' => 'What is the latest project status?', 'route' => '/project/manage'],
            ['name' => 'Top returning client', 'question' => 'Who is our number 1 returning client now?', 'route' => '/client/roi'],
            ['name' => 'Invoice debtor', 'question' => 'Show unpaid invoices and overdue debtors', 'route' => '/commercial/debtors'],
            ['name' => 'Vendor registration expiry', 'question' => 'Which vendor registrations are expiring?', 'route' => '/client/vendor-registrations'],
            ['name' => 'Leave and task scope', 'question' => 'Show leave approvals and overdue tasks', 'route' => '/staff/leaves'],
            ['name' => 'Restricted HR adjacent', 'question' => 'Show staff appraisal and system feedback issues', 'route' => '/staff/appraise'],
        ];
    }

    private function restrictedWarning(string $role, array $sourceTypes): ?string
    {
        if ($role !== 'staff') {
            return null;
        }

        $restricted = array_values(array_intersect($sourceTypes, ['staff', 'appraisal', 'system_feedback']));

        return $restricted ? 'Normal staff smoke returned restricted-adjacent source types: '.implode(', ', $restricted) : null;
    }
}
