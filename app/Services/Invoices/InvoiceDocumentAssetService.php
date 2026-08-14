<?php

namespace App\Services\Invoices;

use App\Support\AppFilePaths;
use Illuminate\Http\Request;

final class InvoiceDocumentAssetService
{
    /** @return array{signature: ?string, stamp: ?string} */
    public function paths(Request $request, object $invoice, ?object $creator): array
    {
        $candidates = [];
        if (! empty($invoice->created_by) && ! empty($creator?->name_code)) {
            $candidates[] = [(string) $invoice->created_by, (string) $creator->name_code];
        }
        $sessionId = (string) $request->session()->get('staff_id', '');
        $sessionCode = (string) $request->session()->get('name_code', '');
        if ($sessionId !== '' && $sessionCode !== '') {
            $candidates[] = [$sessionId, $sessionCode];
        }

        return [
            'signature' => $this->signaturePath($candidates),
            'stamp' => $this->firstReadablePath([
                'invoice-assets/stamp.png', 'invoice-assets/stamp.jpg', 'invoice-assets/stamp.jpeg',
                'signatures/stamp.png', 'signatures/stamp.jpg', 'signatures/stamp.jpeg',
            ]),
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    public function dataUris(Request $request, object $invoice, ?object $creator): array
    {
        $paths = $this->paths($request, $invoice, $creator);

        return [$this->dataUri($paths['signature']), $this->dataUri($paths['stamp'])];
    }

    private function signaturePath(array $candidates): ?string
    {
        foreach ($candidates as [$staffId, $code]) {
            $staffId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $staffId);
            $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $code);
            if ($staffId === '' || $code === '') {
                continue;
            }
            $paths = [];
            foreach (['png', 'jpg', 'jpeg'] as $extension) {
                $paths[] = "signatures/{$staffId}-{$code}.{$extension}";
            }
            foreach (['png', 'jpg', 'jpeg'] as $extension) {
                $paths[] = "invoice-assets/{$staffId}-{$code}.{$extension}";
            }
            $resolved = $this->firstReadablePath($paths);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function firstReadablePath(array $storedPaths): ?string
    {
        foreach ($storedPaths as $storedPath) {
            $path = AppFilePaths::storedPathLocalPath($storedPath);
            if ($path !== null && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function dataUri(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $mime = match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        return "data:{$mime};base64,".base64_encode($bytes);
    }
}
