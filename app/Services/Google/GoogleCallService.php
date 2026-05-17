<?php

namespace App\Services\Google;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoogleCallService extends GoogleBaseService
{

    public function listCalls(Request $request): JsonResponse
    {
        $contactId = (int) ($request->route('id') ?? $request->input('contact_id', 0));
        if ($contactId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid contact_id'], 400);
        }

        try {
            $rows = DB::table('google_call_records')
                ->where('contact_id', $contactId)
                ->select('id', 'contact_id', 'called_at', 'outcome', 'note', 'next_action_at', 'called_by', 'called_by_code', 'duration_sec')
                ->orderByDesc('called_at')
                ->orderByDesc('id')
                ->get();
            return response()->json(['success' => true, 'rows' => $rows]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to load call logs right now.'], 500);
        }
    }

    public function createCall(Request $request): JsonResponse
    {
        $contactId = (int) ($request->route('id') ?? $request->input('contact_id', 0));
        $calledAt  = trim((string) $request->input('called_at', ''));
        $outcome   = trim((string) $request->input('outcome', ''));
        $note      = trim((string) $request->input('note', ''));
        $nextAt    = trim((string) $request->input('next_action_at', ''));
        $duration  = $request->has('duration_sec') ? (int) $request->input('duration_sec') : null;

        if ($contactId <= 0 || $outcome === '') {
            return response()->json(['success' => false, 'message' => 'contact_id and outcome are required'], 400);
        }
        if ($calledAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $calledAt)) {
            return response()->json(['success' => false, 'message' => 'Invalid called_at format'], 400);
        }

        try {
            $row = [
                'contact_id'     => $contactId,
                'outcome'        => $outcome,
                'note'           => $note !== '' ? $note : null,
                'next_action_at' => $nextAt !== '' ? $nextAt : null,
                'called_by'      => $request->session()->get('staff_id'),
                'called_by_code' => $request->session()->get('name_code', 'XXX'),
                'duration_sec'   => $duration,
            ];
            if ($calledAt !== '') {
                $row['called_at'] = $calledAt;
            }

            $id = DB::table('google_call_records')->insertGetId($row);
            return response()->json(['success' => true, 'id' => (int) $id, 'message' => 'Call record added successfully.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to save call record right now.'], 500);
        }
    }

    public function deleteCall(Request $request): JsonResponse
    {
        $callId = (int) $request->route('id');
        if ($callId <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid call id'], 400);
        }

        try {
            $call = DB::table('google_call_records')->where('id', $callId)->select('id', 'called_by')->first();
            if (!$call) {
                return response()->json(['success' => false, 'message' => 'Call log not found'], 404);
            }
            if (!$this->canModify((int) $call->called_by, $request)) {
                return response()->json(['success' => false, 'message' => 'Deletion prohibited. You are not the owner to this log.'], 403);
            }

            DB::table('google_call_records')->where('id', $callId)->delete();
            return response()->json(['success' => true, 'message' => 'Call log deleted.']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to delete call log right now.'], 500);
        }
    }
}
