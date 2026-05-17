<?php

namespace App\Services\Catalog;

use App\Services\Pdf\PdfRenderer;
use App\Http\Requests\Catalog\MarkSupplierPoPaidRequest;
use App\Http\Requests\Catalog\StoreCatalogItemRequest;
use App\Http\Requests\Catalog\StoreSupplierPoRequest;
use App\Http\Requests\Catalog\UpdateCatalogItemRequest;
use App\Services\AuditLogService;
use App\Support\AppFilePaths;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

abstract class CatalogBaseService extends PdfRenderer
{
    protected static bool $dompdfAutoloaderRegistered = false;

    public function __construct(protected AuditLogService $auditLog) {}

    protected function normalizeCatalogRow(object $row): object
    {
        $row->created_at = $row->created_at ? date('Y-m-d H:i:s', strtotime((string) $row->created_at)) : null;
        $row->brochure_url = $this->catalogFilePublicUrl($row->brochure_filename ?: null);
        return $row;
    }

    protected function storeCatalogFile(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $filename = uniqid('catalog_', true) . ($ext !== '' ? ".{$ext}" : '');

        Storage::disk('public')->putFileAs('catalog', $file, $filename);
        return $filename;
    }

    protected function deleteCatalogFile(string $filename): void
    {
        if ($filename === '') {
            return;
        }

        if (str_contains($filename, '/') || str_contains($filename, '\\')) {
            AppFilePaths::deletePublicPath($filename);
        }

        Storage::disk('public')->delete('catalog/'.basename($filename));
    }

    protected function catalogFilePublicUrl(?string $filename): ?string
    {
        if (!$filename) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $filename) || str_starts_with($filename, '//')) {
            return $filename;
        }

        $path = AppFilePaths::publicUrlForStoredPath($filename);
        if (str_starts_with($path, '/storage/catalog/')) {
            return $path;
        }

        if (str_starts_with($filename, '/')) {
            return AppFilePaths::publicUrlForStoredPath($filename);
        }

        return AppFilePaths::publicStorageUrl('catalog/'.basename($filename));
    }
}
