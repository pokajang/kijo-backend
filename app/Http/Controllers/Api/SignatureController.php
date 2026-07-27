<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signature\StoreSignatureRequest;
use App\Services\AuditLogService;
use App\Services\Signatures\StaffSignatureService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    public function __construct(
        private AuditLogService $auditLog,
        private StaffSignatureService $signatures,
    ) {}

    public function show(Request $request)
    {
        $signature = $this->signatures->current(
            (int) $request->session()->get('staff_id', 0),
            (string) $request->session()->get('name_code', ''),
        );

        return response()->json([
            'status' => 'success',
            'url' => $signature['url'] ?? null,
            'data' => $signature
                ? [
                    'available' => true,
                    'url' => $signature['url'],
                    'sha256' => $signature['sha256'],
                    'updated_at' => $signature['updated_at'],
                ]
                : [
                    'available' => false,
                    'url' => null,
                    'sha256' => null,
                    'updated_at' => null,
                ],
        ]);
    }

    public function store(StoreSignatureRequest $request)
    {
        $signature = $this->signatures->store(
            (int) $request->session()->get('staff_id', 0),
            (string) $request->session()->get('name_code', ''),
            $request->file('signature'),
        );
        $this->auditLog->log($request, 'Updated personal signature');

        return response()->json([
            'status' => 'success',
            'url' => $signature['url'],
            'data' => [
                'available' => true,
                'url' => $signature['url'],
                'sha256' => $signature['sha256'],
                'updated_at' => $signature['updated_at'],
            ],
        ]);
    }

    public function file(Request $request)
    {
        $signature = $this->signatures->current(
            (int) $request->session()->get('staff_id', 0),
            (string) $request->session()->get('name_code', ''),
        );
        if (! $signature) {
            return response()->json([
                'status' => 'error',
                'message' => 'Signature not found.',
            ], 404);
        }

        return AppFilePaths::storedPathResponse(
            $signature['path'],
            'personal-signature.'.($signature['mime_type'] === 'image/png' ? 'png' : 'jpg'),
        );
    }
}
