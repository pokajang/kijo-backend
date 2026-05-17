<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Google\GoogleService;

class GoogleController extends Controller
{
    private function googleService(): GoogleService
    {
        return app(GoogleService::class);
    }

    public function placeDetails(Request $request): JsonResponse
    {
        return $this->googleService()->placeDetails($request);
    }

    public function listUnregisteredPlaces(Request $request): JsonResponse
    {
        return $this->googleService()->listUnregisteredPlaces($request);
    }

    public function seedPlaces(Request $request): JsonResponse
    {
        return $this->googleService()->seedPlaces($request);
    }

    public function listContacts(Request $request): JsonResponse
    {
        return $this->googleService()->listContacts($request);
    }

    public function registerContact(Request $request): JsonResponse
    {
        return $this->googleService()->registerContact($request);
    }

    public function updateContact(Request $request): JsonResponse
    {
        return $this->googleService()->updateContact($request);
    }

    public function deleteContact(Request $request): JsonResponse
    {
        return $this->googleService()->deleteContact($request);
    }

    public function listContactsWithCalls(Request $request): JsonResponse
    {
        return $this->googleService()->listContactsWithCalls($request);
    }

    public function listCalls(Request $request): JsonResponse
    {
        return $this->googleService()->listCalls($request);
    }

    public function createCall(Request $request): JsonResponse
    {
        return $this->googleService()->createCall($request);
    }

    public function deleteCall(Request $request): JsonResponse
    {
        return $this->googleService()->deleteCall($request);
    }

    public function callStatistics(Request $request): JsonResponse
    {
        return $this->googleService()->callStatistics($request);
    }

}
