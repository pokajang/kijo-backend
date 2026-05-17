<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoogleService
{
    private function googlePlacesService(): GooglePlacesService
    {
        return app(GooglePlacesService::class);
    }

    private function googleContactService(): GoogleContactService
    {
        return app(GoogleContactService::class);
    }

    private function googleCallService(): GoogleCallService
    {
        return app(GoogleCallService::class);
    }

    private function googleCallStatsService(): GoogleCallStatsService
    {
        return app(GoogleCallStatsService::class);
    }

    public function placeDetails(Request $request): JsonResponse
    {
        return $this->googlePlacesService()->placeDetails($request);
    }

    public function listUnregisteredPlaces(Request $request): JsonResponse
    {
        return $this->googlePlacesService()->listUnregisteredPlaces($request);
    }

    public function seedPlaces(Request $request): JsonResponse
    {
        return $this->googlePlacesService()->seedPlaces($request);
    }

    public function listContacts(Request $request): JsonResponse
    {
        return $this->googleContactService()->listContacts($request);
    }

    public function registerContact(Request $request): JsonResponse
    {
        return $this->googleContactService()->registerContact($request);
    }

    public function updateContact(Request $request): JsonResponse
    {
        return $this->googleContactService()->updateContact($request);
    }

    public function deleteContact(Request $request): JsonResponse
    {
        return $this->googleContactService()->deleteContact($request);
    }

    public function listContactsWithCalls(Request $request): JsonResponse
    {
        return $this->googleContactService()->listContactsWithCalls($request);
    }

    public function listCalls(Request $request): JsonResponse
    {
        return $this->googleCallService()->listCalls($request);
    }

    public function createCall(Request $request): JsonResponse
    {
        return $this->googleCallService()->createCall($request);
    }

    public function deleteCall(Request $request): JsonResponse
    {
        return $this->googleCallService()->deleteCall($request);
    }

    public function callStatistics(Request $request): JsonResponse
    {
        return $this->googleCallStatsService()->callStatistics($request);
    }

}
