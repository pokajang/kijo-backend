<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class AppFilePaths
{
    private const PUBLIC_PREFIXES = [
        'catalog/',
        'knowledge/',
        'sport-time/',
        'whats-new/',
    ];

    public static function tcpdfPath(): string
    {
        return resource_path('pdf/tcpdf/tcpdf.php');
    }

    public static function tcpdfTemplatePath(string $relativePath): string
    {
        return resource_path('pdf/tcpdf/templates/'.ltrim($relativePath, '/\\'));
    }

    public static function publicStorageUrl(string $relativePath): string
    {
        return Storage::disk('public')->url(ltrim(str_replace('\\', '/', $relativePath), '/'));
    }

    public static function publicUrlForStoredPath(?string $publicPath): string
    {
        $publicPath = trim((string) $publicPath);
        if ($publicPath === '') {
            return '';
        }

        $relativePath = self::publicStorageRelativePath($publicPath);
        if ($relativePath !== null) {
            return self::urlForRelativePath($relativePath);
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $publicPath) || str_starts_with($publicPath, '//')) {
            return $publicPath;
        }

        return $publicPath;
    }

    public static function privateFileUrlForStoredPath(?string $storedPath, ?string $downloadName = null): string
    {
        $relativePath = self::publicStorageRelativePath($storedPath);
        if ($relativePath === null || ! self::isSensitiveRelativePath($relativePath)) {
            return '';
        }

        $payload = json_encode([
            'disk' => 'private',
            'path' => $relativePath,
            'name' => self::safeDownloadName($downloadName ?: basename($relativePath)),
        ], JSON_THROW_ON_ERROR);

        $encrypted = Crypt::encryptString($payload);
        $token = rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');

        return url('files/private/'.$token);
    }

    public static function resolvePrivateFileToken(string $token): ?array
    {
        $normalizedToken = strtr($token, '-_', '+/');
        $normalizedToken .= str_repeat('=', (4 - strlen($normalizedToken) % 4) % 4);
        $encrypted = base64_decode($normalizedToken, true);
        if ($encrypted === false) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (($payload['disk'] ?? null) !== 'private') {
            return null;
        }

        $relativePath = self::safeRelativePath((string) ($payload['path'] ?? ''));
        if ($relativePath === null || ! self::isSensitiveRelativePath($relativePath)) {
            return null;
        }

        if (! self::storedPathExists($relativePath)) {
            return null;
        }

        return [
            'path' => $relativePath,
            'name' => self::safeDownloadName((string) ($payload['name'] ?? basename($relativePath))),
        ];
    }

    public static function storedPathResponse(string $storedPath, ?string $downloadName = null)
    {
        $relativePath = self::publicStorageRelativePath($storedPath);
        if ($relativePath === null) {
            abort(404);
        }

        $name = self::safeDownloadName($downloadName ?: basename($relativePath));

        if (self::isSensitiveRelativePath($relativePath)) {
            if (Storage::disk('private')->exists($relativePath)) {
                return Storage::disk('private')->response($relativePath, $name);
            }

            if (Storage::disk('public')->exists($relativePath)) {
                return Storage::disk('public')->response($relativePath, $name);
            }

            abort(404);
        }

        if (! Storage::disk('public')->exists($relativePath)) {
            abort(404);
        }

        return Storage::disk('public')->response($relativePath, $name);
    }

    public static function storedPathExists(?string $storedPath): bool
    {
        $relativePath = self::publicStorageRelativePath($storedPath);
        if ($relativePath === null) {
            return false;
        }

        if (self::isSensitiveRelativePath($relativePath) && Storage::disk('private')->exists($relativePath)) {
            return true;
        }

        return Storage::disk('public')->exists($relativePath);
    }

    public static function storedPathLocalPath(?string $storedPath): ?string
    {
        $relativePath = self::publicStorageRelativePath($storedPath);
        if ($relativePath === null) {
            return null;
        }

        if (self::isSensitiveRelativePath($relativePath) && Storage::disk('private')->exists($relativePath)) {
            return Storage::disk('private')->path($relativePath);
        }

        if (Storage::disk('public')->exists($relativePath)) {
            return Storage::disk('public')->path($relativePath);
        }

        return null;
    }

    public static function storeFileAs(string $folder, UploadedFile $file, string $filename): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $relativePath = trim($folder.'/'.$filename, '/');
        $disk = self::isSensitiveRelativePath($relativePath) ? 'private' : 'public';

        Storage::disk($disk)->putFileAs($folder, $file, $filename);

        return $relativePath;
    }

    public static function put(string $relativePath, string $contents): void
    {
        $relativePath = self::safeRelativePath($relativePath);
        if ($relativePath === null) {
            throw new \InvalidArgumentException('Invalid storage path.');
        }

        $disk = self::isSensitiveRelativePath($relativePath) ? 'private' : 'public';
        Storage::disk($disk)->put($relativePath, $contents);
    }

    public static function copyStoredPath(string $sourcePath, string $targetPath): bool
    {
        $sourceRelativePath = self::publicStorageRelativePath($sourcePath);
        $targetRelativePath = self::publicStorageRelativePath($targetPath);
        if ($sourceRelativePath === null || $targetRelativePath === null) {
            return false;
        }

        $sourceLocalPath = self::storedPathLocalPath($sourceRelativePath);
        if ($sourceLocalPath === null || ! is_file($sourceLocalPath) || ! is_readable($sourceLocalPath)) {
            return false;
        }

        $contents = @file_get_contents($sourceLocalPath);
        if ($contents === false) {
            return false;
        }

        self::put($targetRelativePath, $contents);

        return self::storedPathExists($targetRelativePath);
    }

    public static function deletePublicPath(string $publicPath): void
    {
        $publicPath = trim($publicPath);
        if ($publicPath === '') {
            return;
        }

        $relativePath = self::publicStorageRelativePath($publicPath);
        if ($relativePath !== null) {
            self::deleteStoredPath($relativePath);
            return;
        }

        $legacyPath = self::legacyUploadStoragePath($publicPath);
        if ($legacyPath !== null && is_file($legacyPath)) {
            @unlink($legacyPath);
        }
    }

    public static function deleteStoredPath(?string $storedPath): void
    {
        $relativePath = self::publicStorageRelativePath($storedPath);
        if ($relativePath === null) {
            return;
        }

        Storage::disk('private')->delete($relativePath);
        Storage::disk('public')->delete($relativePath);
    }

    public static function isPublicRelativePath(?string $relativePath): bool
    {
        $relativePath = self::safeRelativePath((string) $relativePath);
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function isSensitiveRelativePath(?string $relativePath): bool
    {
        $relativePath = self::safeRelativePath((string) $relativePath);
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        return ! self::isPublicRelativePath($relativePath);
    }

    public static function legacyUploadStoragePath(string $publicPath): ?string
    {
        $relativePath = self::legacyUploadRelativePath($publicPath);
        if ($relativePath === null) {
            return null;
        }

        return storage_path('app/public/legacy-uploads/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    }

    public static function publicStorageRelativePath(?string $publicPath): ?string
    {
        $publicPath = trim((string) $publicPath);
        if ($publicPath === '') {
            return null;
        }

        $storageBaseUrl = rtrim(Storage::disk('public')->url(''), '/').'/';
        if (str_starts_with($publicPath, $storageBaseUrl)) {
            return self::safeRelativePath(substr($publicPath, strlen($storageBaseUrl)));
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $publicPath) || str_starts_with($publicPath, '//')) {
            return null;
        }

        if (str_starts_with($publicPath, '/storage/')) {
            return self::safeRelativePath(substr($publicPath, strlen('/storage/')));
        }

        $legacyRelativePath = self::legacyUploadRelativePath($publicPath);
        if ($legacyRelativePath !== null) {
            if (self::isPublicRelativePath($legacyRelativePath)) {
                return $legacyRelativePath;
            }

            return 'legacy-uploads/'.$legacyRelativePath;
        }

        if (str_starts_with($publicPath, '/')) {
            return null;
        }

        return self::safeRelativePath($publicPath);
    }

    public static function legacyUploadRelativePath(string $publicPath): ?string
    {
        foreach (['/backend-legacy/uploads/', '/backend/uploads/', '/uploads/', 'uploads/'] as $prefix) {
            if (! str_starts_with($publicPath, $prefix)) {
                continue;
            }

            return self::safeRelativePath(substr($publicPath, strlen($prefix)));
        }

        return null;
    }

    private static function safeRelativePath(string $path): ?string
    {
        $relativePath = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(explode('/', $relativePath), static fn ($part) => $part !== ''));
        if (in_array('..', $parts, true)) {
            return null;
        }

        return implode('/', $parts);
    }

    private static function urlForRelativePath(string $relativePath): string
    {
        return self::isSensitiveRelativePath($relativePath)
            ? self::privateFileUrlForStoredPath($relativePath)
            : self::publicStorageUrl($relativePath);
    }

    private static function safeDownloadName(string $name): string
    {
        $name = basename(str_replace('\\', '/', trim($name)));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'download';

        return trim($name) !== '' ? $name : 'download';
    }
}
