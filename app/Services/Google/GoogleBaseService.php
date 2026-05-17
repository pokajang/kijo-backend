<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class GoogleBaseService
{
    protected const DETAILS_URL    = 'https://maps.googleapis.com/maps/api/place/details/json';

    protected const TEXTSEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

    protected function placesKey(): string
    {
        return (string) env('GOOGLE_PLACES_API_KEY', '');
    }

    protected function googlePlacesErrorMessage(?string $status, mixed $errorMessage = null): string
    {
        $status = strtoupper(trim((string) $status));
        $errorText = is_string($errorMessage) ? trim($errorMessage) : '';

        if ($status === 'REQUEST_DENIED') {
            if (stripos($errorText, 'billing') !== false) {
                return 'Google Places phone lookup is denied because billing is not enabled for the Google Cloud project.';
            }

            if ($errorText !== '') {
                return 'Google Places phone lookup was denied: ' . $errorText;
            }

            return 'Google Places phone lookup was denied. Check the API key, billing, and Places API access.';
        }

        if ($status === 'OVER_QUERY_LIMIT') {
            return 'Google Places phone lookup quota has been exceeded.';
        }

        if ($status === 'INVALID_REQUEST') {
            return 'Google Places phone lookup request is invalid for this place.';
        }

        if ($status === 'NOT_FOUND' || $status === 'ZERO_RESULTS') {
            return 'Google Places has no details for this place.';
        }

        return 'Phone details are unavailable right now.';
    }

    protected function canModify(int $ownerId, Request $request): bool
    {
        $staffId = (int) $request->session()->get('staff_id', 0);
        if ($staffId > 0 && $staffId === $ownerId) {
            return true;
        }
        foreach ((array) $request->session()->get('roles', []) as $role) {
            $lower = strtolower(trim((string) $role));
            if (str_contains($lower, 'admin') || str_contains($lower, 'manager') || str_contains($lower, 'super')) {
                return true;
            }
        }
        return false;
    }
}
