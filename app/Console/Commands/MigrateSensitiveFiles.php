<?php

namespace App\Console\Commands;

use App\Support\AppFilePaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateSensitiveFiles extends Command
{
    protected $signature = 'app:migrate-sensitive-files
        {--commit : Move sensitive public files into private storage. Without this option the command only reports.}';

    protected $description = 'Move non-public-allowlisted files from storage/app/public to storage/app/private while preserving relative paths.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $publicDisk = Storage::disk('public');
        $privateDisk = Storage::disk('private');
        $files = $publicDisk->allFiles();
        $toMove = 0;
        $moved = 0;
        $alreadyPrivate = 0;
        $publicSkipped = 0;
        $failed = 0;

        foreach ($files as $relativePath) {
            $relativePath = str_replace('\\', '/', $relativePath);
            if (AppFilePaths::isPublicRelativePath($relativePath)) {
                $publicSkipped++;
                continue;
            }

            $toMove++;

            if ($privateDisk->exists($relativePath)) {
                $alreadyPrivate++;
                if ($commit && $this->sameStoredFile($relativePath)) {
                    $publicDisk->delete($relativePath);
                }
                continue;
            }

            if (! $commit) {
                continue;
            }

            $stream = $publicDisk->readStream($relativePath);
            if ($stream === false) {
                $failed++;
                $this->warn("Could not read public file: {$relativePath}");
                continue;
            }

            try {
                $privateDisk->put($relativePath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $privateDisk->exists($relativePath)) {
                $failed++;
                $this->warn("Could not write private file: {$relativePath}");
                continue;
            }

            $publicDisk->delete($relativePath);
            $moved++;
        }

        $this->info("Public allowlisted files skipped: {$publicSkipped}.");
        $this->info("Private candidates found: {$toMove}.");
        $this->info("Already private: {$alreadyPrivate}.");
        $this->info($commit ? "Moved to private: {$moved}." : 'Dry run only. Re-run with --commit to move files.');

        if ($failed > 0) {
            $this->error("Failed files: {$failed}.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function sameStoredFile(string $relativePath): bool
    {
        $publicPath = Storage::disk('public')->path($relativePath);
        $privatePath = Storage::disk('private')->path($relativePath);

        return is_file($publicPath)
            && is_file($privatePath)
            && filesize($publicPath) === filesize($privatePath)
            && hash_file('sha256', $publicPath) === hash_file('sha256', $privatePath);
    }
}
