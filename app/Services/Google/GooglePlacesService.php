<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GooglePlacesService extends GoogleBaseService
{

    public function placeDetails(Request $request): JsonResponse
    {
        $placeId = trim((string) $request->input('place_id', ''));
        if ($placeId === '') {
            return response()->json(['error' => 'Missing place_id'], 400);
        }

        try {
            $resp = Http::timeout(10)->get(self::DETAILS_URL, [
                'place_id' => $placeId,
                'fields'   => 'name,formatted_address,formatted_phone_number,international_phone_number,website,types',
                'key'      => $this->placesKey(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Phone details are unavailable right now.',
            ]);
        }

        $data = $resp->json() ?? [];
        $status = $data['status'] ?? null;
        if (!$resp->ok() || ($status !== null && $status !== 'OK')) {
            $message = $this->googlePlacesErrorMessage($status, $data['error_message'] ?? null);

            return response()->json([
                'success' => false,
                'message' => $message,
                'google_status' => $status,
            ]);
        }

        $r = $data['result'] ?? [];
        return response()->json([
            'name'    => $r['name']    ?? null,
            'address' => $r['formatted_address'] ?? null,
            'phone'   => $r['international_phone_number'] ?? ($r['formatted_phone_number'] ?? null),
            'website' => $r['website'] ?? null,
            'types'   => $r['types']   ?? [],
        ]);
    }

    public function listUnregisteredPlaces(Request $request): JsonResponse
    {
        try {
            $rows = DB::select("
                SELECT gp.place_id, gp.name, gp.address_full AS address
                  FROM google_places gp
                 WHERE gp.place_id NOT IN (
                       SELECT gc.place_id FROM google_contacts gc WHERE gc.place_id IS NOT NULL
                 )
                 ORDER BY gp.id DESC
                 LIMIT 500
            ");
            return response()->json(['rows' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => 'Unable to load places right now.'], 500);
        }
    }

    public function seedPlaces(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['error' => 'Missing q'], 400);
        }

        $region      = trim((string) $request->input('region', 'Malaysia'));
        $limit       = max(1, min(50, (int) $request->input('limit', 10)));
        $searchQuery = trim($q . ' ' . $region);

        $inserted = 0;
        $seen     = [];
        $nextPage = null;

        try {
            do {
                $params = $nextPage
                    ? ['pagetoken' => $nextPage, 'key' => $this->placesKey()]
                    : ['query' => $searchQuery, 'region' => 'my', 'key' => $this->placesKey()];

                $resp = Http::timeout(15)->get(self::TEXTSEARCH_URL, $params);
                $data = $resp->json() ?? [];

                if (!$resp->ok() || ($data['status'] ?? '') === 'REQUEST_DENIED') {
                    return response()->json(['error' => 'Google request denied or failed', 'raw' => $data], 502);
                }

                foreach ($data['results'] ?? [] as $r) {
                    if (count($seen) >= $limit) break;
                    $pid = $r['place_id'] ?? null;
                    if (!$pid || isset($seen[$pid])) continue;
                    $seen[$pid] = true;

                    DB::statement("
                        INSERT INTO google_places (place_id, name, address_full, types)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            name         = VALUES(name),
                            address_full = VALUES(address_full),
                            types        = VALUES(types)
                    ", [
                        $pid,
                        $r['name'] ?? '',
                        $r['formatted_address'] ?? ($r['vicinity'] ?? ''),
                        isset($r['types']) ? implode(',', $r['types']) : null,
                    ]);
                    $inserted++;
                }

                $nextPage = $data['next_page_token'] ?? null;
                if ($nextPage && count($seen) < $limit) {
                    sleep(2); // Google requires a short pause before next_page_token is usable
                }
            } while ($nextPage && count($seen) < $limit);

            return response()->json(['inserted' => min($inserted, count($seen)), 'count' => count($seen)]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['error' => 'Server error'], 500);
        }
    }
}
