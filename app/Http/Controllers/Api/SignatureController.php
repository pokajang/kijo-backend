<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signature\StoreSignatureRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    private const MIME_EXT = ['image/jpeg' => '.jpg', 'image/png' => '.png'];

    public function __construct(private AuditLogService $auditLog) {}

    public function show(Request $request)
    {
        $staffId  = $request->session()->get('staff_id');
        $nameCode = $request->session()->get('name_code');
        $prefix   = "{$staffId}-{$nameCode}";

        foreach (['.jpg', '.png'] as $ext) {
            $path = "signatures/{$prefix}{$ext}";
            if (AppFilePaths::storedPathExists($path)) {
                return response()->json([
                    'status' => 'success',
                    'url'    => AppFilePaths::publicUrlForStoredPath($path),
                ]);
            }
        }

        return response()->json(['status' => 'success', 'url' => null]);
    }

    public function store(StoreSignatureRequest $request)
    {
        $staffId  = $request->session()->get('staff_id');
        $nameCode = $request->session()->get('name_code');

        $file     = $request->file('signature');
        $ext      = self::MIME_EXT[$file->getMimeType()] ?? '.jpg';
        $filename = "{$staffId}-{$nameCode}{$ext}";

        // Remove any existing signature for this staff member
        foreach (['.jpg', '.png'] as $oldExt) {
            AppFilePaths::deleteStoredPath("signatures/{$staffId}-{$nameCode}{$oldExt}");
        }

        AppFilePaths::storeFileAs('signatures', $file, $filename);
        $this->auditLog->log($request, 'Updated personal signature');

        return response()->json([
            'status' => 'success',
            'url'    => AppFilePaths::publicUrlForStoredPath("signatures/{$filename}"),
        ]);
    }
}
