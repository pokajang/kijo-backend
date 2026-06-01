<?php

namespace App\Services\Assistant\Sources;

use App\Services\Assistant\AssistantContextProvider;
use App\Services\Assistant\AssistantContextQualityService;
use App\Services\Assistant\AssistantText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

abstract class ModuleContextProvider implements AssistantContextProvider
{
    public function __construct(protected readonly AssistantText $text) {}

    protected function hasToken(string $question, array $tokens): bool
    {
        $questionTokens = array_flip($this->text->tokens($question));

        foreach ($tokens as $token) {
            if (isset($questionTokens[$token])) {
                return true;
            }
        }

        return false;
    }

    protected function hasListIntent(string $question): bool
    {
        return (bool) preg_match('/\b(all|available|list|show|find|search|which|what|active|inactive|open|current|recent)\b/i', $question);
    }

    protected function hasAnyRole(Request $request, array $allowedRoles): bool
    {
        $roles = $request->session()->get('roles', []);
        if (! is_array($roles)) {
            $roles = [$roles];
        }
        $normalizedRoles = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            $roles,
        );
        if (in_array('system admin', $normalizedRoles, true)) {
            return true;
        }

        $normalizedAllowed = array_map(
            static fn (string $role): string => strtolower(trim($role)),
            $allowedRoles,
        );

        return collect($normalizedRoles)
            ->intersect($normalizedAllowed)
            ->isNotEmpty();
    }

    protected function clonedRequest(Request $baseRequest, string $uri, array $query = []): Request
    {
        $request = Request::create($uri, 'GET', $query);
        $request->setLaravelSession($baseRequest->session());
        $request->headers->replace($baseRequest->headers->all());

        return $request;
    }

    /**
     * @template T of FormRequest
     *
     * @param  class-string<T>  $requestClass
     * @return T
     */
    protected function formRequest(string $requestClass, Request $baseRequest, string $uri, array $query = []): FormRequest
    {
        $base = Request::create($uri, 'GET', $query);
        $base->setLaravelSession($baseRequest->session());
        $base->headers->replace($baseRequest->headers->all());

        /** @var T $request */
        $request = $requestClass::createFromBase($base);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    protected function responseData(callable $callback): array
    {
        try {
            $response = $callback();
            if ($response instanceof JsonResponse) {
                $payload = $response->getData(true);

                return is_array($payload) ? $payload : [];
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return [];
    }

    protected function source(
        string $slug,
        string $sourceType,
        string $title,
        string $route,
        array $payload,
        int $score,
        string $category,
        int $excerptLimit = 2500,
        array $metadata = [],
    ): ?array {
        $excerpt = $this->text->excerpt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', $excerptLimit);
        if ($excerpt === '') {
            return null;
        }

        $freshnessLabel = $this->freshnessLabel();
        $lifecycle = $this->sourceLifecycleMetadata($payload);

        $source = array_merge([
            'id' => crc32($slug),
            'type' => $sourceType,
            'source_type' => $sourceType,
            'title' => $title,
            'slug' => $slug,
            'summary' => $freshnessLabel,
            'category' => $category,
            'related_route' => $route,
            'excerpt' => $excerpt,
            'fingerprint' => sha1($slug.'|'.$excerpt),
            'freshness_label' => $freshnessLabel,
            'score' => $score,
        ], $lifecycle, $metadata);

        return app(AssistantContextQualityService::class)->annotateSource($source, $this->key());
    }

    protected function freshnessLabel(): string
    {
        return 'As of '.Carbon::now()->format('d M Y, H:i');
    }

    private function sourceLifecycleMetadata(array $payload): array
    {
        $status = $this->findLifecycleValue($payload, ['status', 'stage', 'state', 'approval_status', 'payment_status']);
        $isDeleted = $this->findDeletedState($payload);
        $freshness = $this->freshnessForLifecycle($status, $isDeleted);

        return array_filter([
            'source_status' => $status,
            'source_is_deleted' => $isDeleted,
            'source_freshness_label' => $freshness,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function findLifecycleValue(array $payload, array $keys, int $depth = 0): ?string
    {
        if ($depth > 3) {
            return null;
        }

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $keys, true)) {
                $status = trim((string) $value);
                if ($status !== '') {
                    return $status;
                }
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $status = $this->findLifecycleValue($value, $keys, $depth + 1);
                if ($status !== null) {
                    return $status;
                }
            }
        }

        return null;
    }

    private function findDeletedState(array $payload, int $depth = 0): ?bool
    {
        if ($depth > 3) {
            return null;
        }

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($normalizedKey === 'is_deleted') {
                return (bool) $value;
            }
            if ($normalizedKey === 'deleted_at') {
                return $value !== null && trim((string) $value) !== '';
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $deleted = $this->findDeletedState($value, $depth + 1);
                if ($deleted !== null) {
                    return $deleted;
                }
            }
        }

        return null;
    }

    private function freshnessForLifecycle(?string $status, ?bool $isDeleted): ?string
    {
        if ($isDeleted === true) {
            return 'Deleted';
        }

        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'archived', 'archive' => 'Archived',
            'draft' => 'Draft',
            'stale' => 'Stale',
            'inactive', 'disabled' => 'Inactive',
            default => null,
        };
    }
}
