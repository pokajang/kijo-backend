<?php

namespace App\Services\Signatures;

use App\Support\AppFilePaths;
use Illuminate\Http\UploadedFile;

class StaffSignatureService
{
    private const EXTENSIONS = ['jpg', 'png'];

    public function current(int $staffId, string $nameCode): ?array
    {
        $prefix = $this->prefix($staffId, $nameCode);
        if ($prefix === null) {
            return null;
        }

        foreach (self::EXTENSIONS as $extension) {
            $path = "signatures/{$prefix}.{$extension}";
            AppFilePaths::migrateSensitivePathToPrivate($path);
            $localPath = AppFilePaths::storedPathLocalPath($path);
            if ($localPath === null || ! is_file($localPath) || ! is_readable($localPath)) {
                continue;
            }

            $sha256 = hash_file('sha256', $localPath);

            return [
                'path' => $path,
                'url' => route('personal-signature.file').'?v='.rawurlencode(substr($sha256, 0, 16)),
                'sha256' => $sha256,
                'mime_type' => $extension === 'png' ? 'image/png' : 'image/jpeg',
                'updated_at' => date(DATE_ATOM, (int) filemtime($localPath)),
            ];
        }

        return null;
    }

    public function store(int $staffId, string $nameCode, UploadedFile $file): array
    {
        $prefix = $this->prefix($staffId, $nameCode);
        if ($prefix === null) {
            throw new \InvalidArgumentException('Authenticated staff identity is incomplete.');
        }

        $extension = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $targetPath = "signatures/{$prefix}.{$extension}";
        $operationId = bin2hex(random_bytes(8));
        $stagingPath = null;
        $backupPath = null;
        $existing = $this->current($staffId, $nameCode);

        try {
            if ($existing) {
                $backupExtension = $existing['mime_type'] === 'image/png' ? 'png' : 'jpg';
                $backupPath = "signature-replacements/{$operationId}/backup.{$backupExtension}";
                if (! AppFilePaths::copyStoredPath($existing['path'], $backupPath)) {
                    throw new \RuntimeException('Unable to preserve the existing signature during replacement.');
                }
            }

            $stagingPath = AppFilePaths::storeFileAs(
                'signature-replacements',
                $file,
                "{$operationId}.{$extension}",
            );
            $stagingLocalPath = AppFilePaths::storedPathLocalPath($stagingPath);
            if ($stagingLocalPath === null || ! is_readable($stagingLocalPath)) {
                throw new \RuntimeException('Uploaded signature could not be verified.');
            }
            $expectedSha256 = hash_file('sha256', $stagingLocalPath);

            if (! AppFilePaths::copyStoredPath($stagingPath, $targetPath)) {
                throw new \RuntimeException('Unable to store the new signature.');
            }
            $targetLocalPath = AppFilePaths::storedPathLocalPath($targetPath);
            if ($targetLocalPath === null
                || ! is_readable($targetLocalPath)
                || ! hash_equals($expectedSha256, hash_file('sha256', $targetLocalPath))) {
                throw new \RuntimeException('Stored signature failed verification.');
            }

            foreach (self::EXTENSIONS as $candidateExtension) {
                $candidatePath = "signatures/{$prefix}.{$candidateExtension}";
                if ($candidatePath !== $targetPath) {
                    AppFilePaths::deleteStoredPath($candidatePath);
                }
            }
            AppFilePaths::deletePublicDiskPath($targetPath);
        } catch (\Throwable $exception) {
            if ($backupPath && $existing) {
                AppFilePaths::copyStoredPath($backupPath, $existing['path']);
            } elseif (! $existing) {
                AppFilePaths::deleteStoredPath($targetPath);
            }

            throw $exception;
        } finally {
            AppFilePaths::deleteStoredPath($stagingPath);
            AppFilePaths::deleteStoredPath($backupPath);
        }

        return $this->current($staffId, $nameCode)
            ?? throw new \RuntimeException('Stored signature could not be verified.');
    }

    public function snapshot(array $signature, string $submissionUuid): array
    {
        $extension = $signature['mime_type'] === 'image/png' ? 'png' : 'jpg';
        $attemptId = bin2hex(random_bytes(8));
        $target = "handbook-signatures/{$submissionUuid}/{$attemptId}/signature.{$extension}";

        if (! AppFilePaths::copyStoredPath((string) $signature['path'], $target)) {
            throw new \RuntimeException('Unable to preserve the selected signature.');
        }

        $localPath = AppFilePaths::storedPathLocalPath($target);
        if ($localPath === null || ! is_readable($localPath)) {
            AppFilePaths::deleteStoredPath($target);
            throw new \RuntimeException('Preserved signature could not be verified.');
        }

        $sha256 = hash_file('sha256', $localPath);
        if (! hash_equals((string) $signature['sha256'], $sha256)) {
            AppFilePaths::deleteStoredPath($target);
            throw new \RuntimeException('The personal signature changed while it was being preserved.');
        }

        return [
            'path' => $target,
            'sha256' => $sha256,
            'mime_type' => $signature['mime_type'],
        ];
    }

    public function verifySnapshot(?string $path, ?string $expectedSha256): bool
    {
        if (! $path || ! $expectedSha256) {
            return false;
        }

        $localPath = AppFilePaths::storedPathLocalPath($path);

        return $localPath !== null
            && is_readable($localPath)
            && hash_equals($expectedSha256, hash_file('sha256', $localPath));
    }

    private function prefix(int $staffId, string $nameCode): ?string
    {
        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', trim($nameCode));

        return $staffId > 0 && $safeCode !== '' ? "{$staffId}-{$safeCode}" : null;
    }
}
