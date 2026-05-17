<?php

namespace App\Services\Clients;

use App\Http\Requests\Client\DeleteUnassignedClientPicRequest;
use App\Http\Requests\Client\ListClientsRequest;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UnassignClientPicRequest;
use App\Http\Requests\Client\UpdateClientPicRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class ClientBaseService
{
    public function __construct(protected AuditLogService $auditLog) {}

    protected function composeState(string $state, string $country, string $intlCountry): string
    {
        $state = trim($state);
        $country = trim($country);
        $intlCountry = trim($intlCountry);

        if ($country !== '' && strcasecmp($country, 'Malaysia') !== 0 && $intlCountry !== '' && stripos($state, $intlCountry) === false) {
            $state = trim(implode(', ', array_filter([$state, $intlCountry])));
        }

        return $state;
    }

    protected function normalizeBranchCountry(string $countryRaw, string $intlCountry): string
    {
        $countryRaw = trim($countryRaw);
        $intlCountry = trim($intlCountry);

        if ($countryRaw === 'Other') {
            return $intlCountry;
        }

        if ($countryRaw === '' && $intlCountry !== '') {
            return $intlCountry;
        }

        if ($countryRaw === '') {
            return 'Malaysia';
        }

        return $countryRaw;
    }

    protected function success(mixed $data = null, ?string $message = null, int $statusCode = 200, ?array $pagination = null): JsonResponse
    {
        $payload = ['status' => 'success'];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($pagination !== null) {
            $payload['pagination'] = $pagination;
        }

        return response()->json($payload, $statusCode);
    }

    protected function error(string $message, int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], $statusCode);
    }
}
