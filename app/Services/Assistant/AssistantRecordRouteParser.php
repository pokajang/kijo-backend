<?php

namespace App\Services\Assistant;

class AssistantRecordRouteParser
{
    public function proposalRoute(string $currentRoute): ?array
    {
        $path = $this->path($currentRoute);
        if (! preg_match('~^/templates/proposals/([^/?]+)/(\d+)(?:$|[/?#])~i', $path, $matches)) {
            return null;
        }

        $type = $this->normalizeProposalType($matches[1]);
        $id = (int) $matches[2];

        return $type && $id > 0 ? ['type' => $type, 'id' => $id] : null;
    }

    public function quoteRoute(string $currentRoute): ?array
    {
        $path = $this->path($currentRoute);
        if ($path !== '/crm/quotes') {
            return null;
        }

        $query = [];
        parse_str((string) (parse_url($currentRoute, PHP_URL_QUERY) ?? ''), $query);
        $service = $this->normalizeQuoteService((string) ($query['service'] ?? ''));
        $id = (int) ($query['quoteId'] ?? $query['quote_id'] ?? $query['id'] ?? 0);

        return $service && $id > 0 ? ['service' => $service, 'id' => $id] : null;
    }

    public function normalizeProposalType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'training', 'training-tab' => 'training',
            'ih', 'industrial-hygiene', 'industrial_hygiene' => 'ih',
            'manpower', 'manpower-supply', 'manpower_supply' => 'manpower',
            'special', 'special-service', 'special_service' => 'special',
            default => null,
        };
    }

    public function normalizeQuoteService(string $service): ?string
    {
        return match (strtolower(trim($service))) {
            'equipment', 'equipment-tab', 'equipment-supply', 'equipment_supply' => 'equipment',
            'training', 'training-tab' => 'training',
            'ih', 'ih-tab', 'industrial-hygiene', 'industrial_hygiene' => 'ih',
            'manpower', 'manpower-tab', 'manpower-supply', 'manpower_supply' => 'manpower',
            'special', 'special-tab', 'special-service', 'special_service' => 'special',
            default => null,
        };
    }

    public function proposalDetailRoute(string $type, int $id): string
    {
        $slug = match ($type) {
            'ih' => 'industrial-hygiene',
            'special' => 'special-service',
            default => $type,
        };

        return "/templates/proposals/{$slug}/{$id}";
    }

    public function quoteRouteFor(string $service, int $id): string
    {
        return "/crm/quotes?service={$service}&edit=true&quoteId={$id}";
    }

    private function path(string $currentRoute): string
    {
        return rtrim((string) (parse_url($currentRoute, PHP_URL_PATH) ?: $currentRoute), '/') ?: '/';
    }
}
