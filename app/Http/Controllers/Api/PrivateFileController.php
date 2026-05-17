<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;

class PrivateFileController extends Controller
{
    public function show(Request $request, string $token)
    {
        $resolved = AppFilePaths::resolvePrivateFileToken($token);
        if ($resolved === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'File not found.',
            ], 404);
        }

        return AppFilePaths::storedPathResponse($resolved['path'], $resolved['name']);
    }
}
