<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoogleContactService extends GoogleBaseService
{

    public function listContacts(Request $request): JsonResponse
    {
        try {
            $q     = trim((string) $request->input('q', ''));
            $year  = (int) $request->input('year', 0);
            $fetchAll = $year >= 2000 && $year <= 2100 && (bool) $request->boolean('all');
            $maxLimit = ($year >= 2000 && $year <= 2100) ? 2000 : 200;
            $limit = max(1, min($maxLimit, (int) $request->input('limit', 100)));

            $query = DB::table('google_contacts')
                ->select('id', 'name', 'phone', 'website', 'address', 'created_at', 'created_by_code')
                ->orderByDesc('created_at');
            if (!$fetchAll) {
                $query->limit($limit);
            }

            if ($q !== '') {
                $like = "%{$q}%";
                $query->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('address', 'like', $like);
                });
            }
            if ($year >= 2000 && $year <= 2100) {
                $query->whereYear('created_at', $year);
            }

            return response()->json(['success' => true, 'rows' => $query->get()]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to load contacts right now.'], 500);
        }
    }

    public function registerContact(Request $request): JsonResponse
    {
        $name    = trim((string) $request->input('name', ''));
        $phone   = trim((string) $request->input('phone', ''));
        $addr    = trim((string) $request->input('address', ''));
        $note    = trim((string) $request->input('note', ''));
        $placeId = trim((string) $request->input('place_id', ''));
        $website = trim((string) $request->input('website', ''));

        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Name is required'], 400);
        }

        $phoneNorm = preg_replace('/\D+/', '', $phone) ?: null;

        try {
            if ($placeId !== '') {
                $existing = DB::table('google_contacts')->where('place_id', $placeId)->value('id');
                if ($existing) {
                    return response()->json(['success' => true, 'id' => (int) $existing, 'message' => 'Contact already exists. Using existing record. No action further needed from you.']);
                }
            }

            if ($phoneNorm !== null) {
                $existing = DB::table('google_contacts')->where('phone_normalized', $phoneNorm)->value('id');
                if ($existing) {
                    return response()->json(['success' => true, 'id' => (int) $existing, 'message' => 'Contact already exists. Using existing record. No action further needed from you.']);
                }
            }

            $id = DB::table('google_contacts')->insertGetId([
                'place_id'         => $placeId ?: null,
                'name'             => $name,
                'phone'            => $phone ?: null,
                'phone_normalized' => $phoneNorm,
                'address'          => $addr ?: null,
                'note'             => $note ?: null,
                'website'          => $website ?: null,
                'source'           => 'user',
                'created_by'       => $request->session()->get('staff_id'),
                'created_by_code'  => $request->session()->get('name_code', 'XXX'),
            ]);

            return response()->json(['success' => true, 'id' => (int) $id]);
        } catch (\Throwable $e) {
            // Race-condition duplicate
            if ($e instanceof \Illuminate\Database\QueryException && str_contains($e->getMessage(), '1062')) {
                $fallback = $placeId !== ''
                    ? DB::table('google_contacts')->where('place_id', $placeId)->value('id')
                    : ($phoneNorm !== null
                        ? DB::table('google_contacts')->where('phone_normalized', $phoneNorm)->value('id')
                        : DB::table('google_contacts')->where('name', $name)->where('address', $addr ?: null)->value('id'));

                if ($fallback) {
                    return response()->json(['success' => true, 'id' => (int) $fallback, 'message' => 'Contact already exists. Using existing record. No action further needed from you.']);
                }
            }
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to register contact right now.'], 500);
        }
    }

    public function updateContact(Request $request): JsonResponse
    {
        $contactId = (int) $request->route('id');
        $name      = trim((string) $request->input('name', ''));
        $phone     = trim((string) $request->input('phone', ''));
        $address   = trim((string) $request->input('address', ''));
        $website   = trim((string) $request->input('website', ''));

        if ($contactId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid contact id'], 400);
        }
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Company name is required'], 400);
        }

        $phoneNorm = preg_replace('/\D+/', '', $phone) ?: null;

        try {
            $contact = DB::table('google_contacts')->where('id', $contactId)->select('id', 'created_by')->first();
            if (!$contact) {
                return response()->json(['success' => false, 'message' => 'Contact not found'], 404);
            }
            if (!$this->canModify((int) $contact->created_by, $request)) {
                return response()->json(['success' => false, 'message' => 'Update prohibited. You are not the owner of this contact.'], 403);
            }

            DB::table('google_contacts')->where('id', $contactId)->update([
                'name'             => $name,
                'phone'            => $phone !== '' ? $phone : null,
                'phone_normalized' => $phoneNorm,
                'address'          => $address !== '' ? $address : null,
                'website'          => $website !== '' ? $website : null,
            ]);

            return response()->json(['success' => true, 'message' => 'Contact updated successfully.']);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), '1062')) {
                return response()->json(['success' => false, 'message' => 'A contact with this phone already exists.'], 409);
            }
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to update contact right now.'], 500);
        }
    }

    public function deleteContact(Request $request): JsonResponse
    {
        $contactId = (int) $request->route('id');
        if ($contactId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid contact id'], 400);
        }

        try {
            $contact = DB::table('google_contacts')->where('id', $contactId)->select('id', 'created_by')->first();
            if (!$contact) {
                return response()->json(['success' => false, 'message' => 'Contact not found'], 404);
            }
            if (!$this->canModify((int) $contact->created_by, $request)) {
                return response()->json(['success' => false, 'message' => 'Deletion prohibited. You are not the owner of this contact.'], 403);
            }

            $callCount = DB::table('google_call_records')->where('contact_id', $contactId)->count();
            if ($callCount > 0) {
                return response()->json(['success' => false, 'message' => 'Cannot delete contact with existing call logs. Delete call logs first.'], 409);
            }

            DB::table('google_contacts')->where('id', $contactId)->delete();
            return response()->json(['success' => true, 'message' => 'Contact deleted successfully.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to delete contact right now.'], 500);
        }
    }

    public function listContactsWithCalls(Request $request): JsonResponse
    {
        try {
            $q     = trim((string) $request->input('q', ''));
            $year  = (int) $request->input('year', 0);
            $fetchAll = $year >= 2000 && $year <= 2100 && (bool) $request->boolean('all');
            $maxLimit = ($year >= 2000 && $year <= 2100) ? 2000 : 200;
            $limit = max(1, min($maxLimit, (int) $request->input('limit', 100)));

            $query = DB::table('google_contacts')
                ->select('id', 'name', 'phone', 'website', 'address', 'created_at', 'created_by_code')
                ->orderByDesc('created_at');
            if (!$fetchAll) {
                $query->limit($limit);
            }

            if ($q !== '') {
                $like = "%{$q}%";
                $query->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('address', 'like', $like);
                });
            }
            if ($year >= 2000 && $year <= 2100) {
                $query->whereExists(function ($sub) use ($year) {
                    $sub->select(DB::raw(1))
                        ->from('google_call_records as gcr')
                        ->whereColumn('gcr.contact_id', 'google_contacts.id')
                        ->whereRaw('YEAR(COALESCE(gcr.called_at, gcr.created_at)) = ?', [$year]);
                });
            }

            $contacts = $query->get()->map(fn($c) => (array) $c)->toArray();
            if (empty($contacts)) {
                return response()->json(['success' => true, 'rows' => []]);
            }

            $contactIds = array_column($contacts, 'id');
            $callGroups = DB::table('google_call_records')
                ->whereIn('contact_id', $contactIds)
                ->select('id', 'contact_id', 'called_at', 'outcome', 'note', 'next_action_at', 'called_by', 'called_by_code', 'duration_sec')
                ->when($year >= 2000 && $year <= 2100, function ($callQuery) use ($year) {
                    $callQuery->whereRaw('YEAR(COALESCE(called_at, created_at)) = ?', [$year]);
                })
                ->orderByDesc('called_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('contact_id');

            foreach ($contacts as &$contact) {
                $contact['calls'] = ($callGroups->get($contact['id']) ?? collect())->values()->toArray();
            }
            unset($contact);

            return response()->json(['success' => true, 'rows' => $contacts]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to load call records right now.'], 500);
        }
    }
}
