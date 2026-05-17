<?php

namespace App\Services\Meetings;

use App\Support\AppFilePaths;
use Illuminate\Http\Request;

class MeetingAttachmentService
{
    public function store(Request $request): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }

        $file = $request->file('attachment');
        if (! $file || ! $file->isValid()) {
            return ['error' => 'Attachment upload error.'];
        }
        if ($file->getSize() > (10 * 1024 * 1024)) {
            return ['error' => 'Attachment must be smaller than 10 MB.'];
        }

        $allowed = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        $mime = (string) $file->getMimeType();
        if (! isset($allowed[$mime])) {
            return ['error' => 'Allowed attachments: PDF, DOC, DOCX, TXT, JPG, PNG.'];
        }

        $year = date('Y');
        $safeOriginal = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file->getClientOriginalName());
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $storedPath = AppFilePaths::storeFileAs("meetings/{$year}", $file, $storedName);
        if (! $storedPath || ! AppFilePaths::storedPathExists($storedPath)) {
            return ['error' => 'Failed to store uploaded attachment.'];
        }

        return [
            'absolute_path' => AppFilePaths::storedPathLocalPath($storedPath) ?? '',
            'public_path' => AppFilePaths::publicUrlForStoredPath($storedPath),
            'original_name' => $safeOriginal,
            'size' => $file->getSize(),
            'mime' => $mime,
        ];
    }

    public function deletePublicPath(string $publicPath): void
    {
        AppFilePaths::deletePublicPath($publicPath);
    }

    public function deleteAbsoluteFile(string $absolutePath): void
    {
        if ($absolutePath !== '' && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
