<?php

namespace App\Console\Commands;

use App\Support\AppFilePaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MigrateLegacyUploads extends Command
{
    protected $signature = 'app:migrate-legacy-uploads
        {--source= : Source legacy/public uploads directory, or LEGACY_UPLOAD_SOURCE env}
        {--commit : Copy files and update DB paths. Without this option the command only reports.}';

    protected $description = 'Copy legacy/public uploads into Laravel storage and rewrite DB paths to Laravel storage references.';

    public function handle(): int
    {
        $source = (string) ($this->option('source') ?: env('LEGACY_UPLOAD_SOURCE', ''));
        $destination = storage_path('app/public/legacy-uploads');
        $commit = (bool) $this->option('commit');

        if ($source === '') {
            $this->error('No legacy upload source supplied.');
            return self::FAILURE;
        } elseif (! is_dir($source)) {
            $this->error("Legacy upload source does not exist: {$source}");
            return self::FAILURE;
        } else {
            $this->copyUploads($source, $destination, $commit, 'Legacy upload');
            $this->copyPublicMirrorUploads($source, $commit);
            $this->copyInvoiceAssets($source, $commit);
            $this->copySignatureUploads($source, $commit);
        }

        $this->rewriteDatabasePaths($commit);

        if (! $commit) {
            $this->info('Dry run only. Re-run with --commit to copy files and update DB paths.');
        }

        return self::SUCCESS;
    }

    private function copyUploads(string $source, string $destination, bool $commit, string $label): void
    {
        $files = File::allFiles($source);
        $copied = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if ($this->sameFileAlreadyPresent($target, $file->getPathname(), $file->getSize())) {
                $skipped++;
                continue;
            }

            $copied++;
            if ($commit) {
                File::ensureDirectoryExists(dirname($target));
                File::copy($file->getPathname(), $target);
            }
        }

        $this->info("{$label} files checked: ".count($files)."; to copy: {$copied}; already present: {$skipped}.");
    }

    private function copyPublicMirrorUploads(string $source, bool $commit): void
    {
        $subdirectories = [
            'catalog',
            'payments',
            'project',
            'proposal-templates',
            'signatures',
            'procedures',
            'sport-time',
        ];

        foreach ($subdirectories as $subdirectory) {
            $subdirectorySource = rtrim($source, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $subdirectory);
            if (! is_dir($subdirectorySource)) {
                continue;
            }

            $this->copyUploads(
                $subdirectorySource,
                storage_path('app/public/'.str_replace('/', DIRECTORY_SEPARATOR, $subdirectory)),
                $commit,
                ucfirst($subdirectory).' public upload'
            );
        }
    }

    private function copyInvoiceAssets(string $source, bool $commit): void
    {
        $invoiceSource = rtrim($source, '/\\').DIRECTORY_SEPARATOR.'invoice';
        if (! is_dir($invoiceSource)) {
            return;
        }

        $files = File::files($invoiceSource);
        $copiedAssets = 0;
        $copiedSignatures = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());
            if (! in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                $skipped++;
                continue;
            }

            $filename = $file->getFilename();
            $assetTarget = storage_path('app/public/invoice-assets/'.$filename);
            if (! $this->sameFileAlreadyPresent($assetTarget, $file->getPathname(), $file->getSize())) {
                $copiedAssets++;
                if ($commit) {
                    File::ensureDirectoryExists(dirname($assetTarget));
                    File::copy($file->getPathname(), $assetTarget);
                }
            }

            if (preg_match('/^\d+-[A-Za-z0-9_-]+\.(?:png|jpe?g)$/i', $filename) === 1) {
                $signatureTarget = storage_path('app/public/signatures/'.$filename);
                if (! $this->sameFileAlreadyPresent($signatureTarget, $file->getPathname(), $file->getSize())) {
                    $copiedSignatures++;
                    if ($commit) {
                        File::ensureDirectoryExists(dirname($signatureTarget));
                        File::copy($file->getPathname(), $signatureTarget);
                    }
                }
            }
        }

        $this->info("Invoice asset files checked: ".count($files)."; invoice-assets to copy: {$copiedAssets}; signatures to copy: {$copiedSignatures}; non-image skipped: {$skipped}.");
    }

    private function copySignatureUploads(string $source, bool $commit): void
    {
        $signatureSource = rtrim($source, '/\\').DIRECTORY_SEPARATOR.'signatures';
        if (! is_dir($signatureSource)) {
            return;
        }

        $this->copyUploads($signatureSource, storage_path('app/public/signatures'), $commit, 'Signature upload');

        $files = File::files($signatureSource);
        $copiedStamp = 0;
        foreach ($files as $file) {
            if (! preg_match('/^stamp\.(?:png|jpe?g)$/i', $file->getFilename())) {
                continue;
            }

            $target = storage_path('app/public/invoice-assets/'.$file->getFilename());
            if ($this->sameFileAlreadyPresent($target, $file->getPathname(), $file->getSize())) {
                continue;
            }

            $copiedStamp++;
            if ($commit) {
                File::ensureDirectoryExists(dirname($target));
                File::copy($file->getPathname(), $target);
            }
        }

        $this->info("Signature stamp files checked: ".count($files)."; invoice-assets to copy: {$copiedStamp}.");
    }

    private function sameFileAlreadyPresent(string $target, string $source, int $size): bool
    {
        if (! is_file($target) || filesize($target) !== $size) {
            return false;
        }

        return hash_file('sha256', $target) === hash_file('sha256', $source);
    }

    private function rewriteDatabasePaths(bool $commit): void
    {
        $targets = [
            ['table' => 'procedures', 'column' => 'file_path'],
            ['table' => 'meeting_minutes', 'column' => 'attachment_path'],
            ['table' => 'sport_events', 'column' => 'image_path'],
            ['table' => 'vendor_payments', 'column' => 'receipt_path'],
            ['table' => 'project_expenses', 'column' => 'file_path'],
            ['table' => 'proposal_special_attachments', 'column' => 'stored_path'],
            ['table' => 'proposal_special_attachments', 'column' => 'file_url'],
            ['table' => 'catalog_items', 'column' => 'brochure_filename'],
        ];

        foreach ($targets as $target) {
            $table = $target['table'];
            $column = $target['column'];

            if (! DB::getSchemaBuilder()->hasTable($table) || ! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                $this->warn("Skipping {$table}.{$column}; table or column is missing.");
                continue;
            }

            $rows = DB::table($table)
                ->select(['id', $column])
                ->where(function ($query) use ($column) {
                    $query->where($column, 'like', '/backend/uploads/%')
                        ->orWhere($column, 'like', '/backend-legacy/uploads/%')
                        ->orWhere($column, 'like', '/uploads/%')
                        ->orWhere($column, 'like', 'uploads/%');
                })
                ->get();

            $updated = 0;
            foreach ($rows as $row) {
                $nextPath = AppFilePaths::publicStorageRelativePath((string) $row->{$column});
                if ($nextPath === null || $nextPath === '' || $nextPath === (string) $row->{$column}) {
                    continue;
                }

                $updated++;
                if ($commit) {
                    DB::table($table)->where('id', $row->id)->update([$column => $nextPath]);
                }
            }

            $this->info("{$table}.{$column}: {$updated} legacy path(s) ".($commit ? 'updated' : 'would be updated').'.');
        }

        $this->backfillProposalSpecialAttachmentStoredPath($commit);
    }

    private function backfillProposalSpecialAttachmentStoredPath(bool $commit): void
    {
        $table = 'proposal_special_attachments';
        if (
            ! DB::getSchemaBuilder()->hasTable($table)
            || ! DB::getSchemaBuilder()->hasColumn($table, 'stored_path')
            || ! DB::getSchemaBuilder()->hasColumn($table, 'file_url')
        ) {
            return;
        }

        $rows = DB::table($table)
            ->select(['id', 'stored_path', 'file_url'])
            ->where(function ($query) {
                $query->whereNull('stored_path')->orWhere('stored_path', '');
            })
            ->whereNotNull('file_url')
            ->where('file_url', '<>', '')
            ->get();

        $updated = 0;
        foreach ($rows as $row) {
            $relativePath = AppFilePaths::publicStorageRelativePath((string) $row->file_url);
            if ($relativePath === null || $relativePath === '') {
                continue;
            }

            $updated++;
            if ($commit) {
                DB::table($table)->where('id', $row->id)->update(['stored_path' => $relativePath]);
            }
        }

        $this->info("{$table}.stored_path: {$updated} path(s) ".($commit ? 'backfilled' : 'would be backfilled').' from file_url.');
    }
}
