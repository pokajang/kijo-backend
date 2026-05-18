<?php

namespace Tests\Unit;

use App\Support\AppFilePaths;
use Tests\TestCase;

class AppFilePathsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => '/storage']);
    }

    public function test_legacy_backend_upload_paths_are_mapped_to_private_urls(): void
    {
        $this->assertPrivateFileUrl(
            AppFilePaths::publicUrlForStoredPath('/backend/uploads/procedures/2026/file.pdf')
        );

        $this->assertPrivateFileUrl(
            AppFilePaths::publicUrlForStoredPath('/backend-legacy/uploads/meetings/2026/minutes.pdf')
        );

        $this->assertPrivateFileUrl(
            AppFilePaths::publicUrlForStoredPath('/uploads/payments/2026/05/receipt.pdf')
        );
    }

    public function test_sensitive_laravel_storage_paths_are_mapped_to_private_urls(): void
    {
        $this->assertPrivateFileUrl(AppFilePaths::publicUrlForStoredPath('/storage/procedures/2026/file.pdf'));
    }

    public function test_sensitive_relative_disk_paths_are_mapped_to_private_urls(): void
    {
        $this->assertPrivateFileUrl(AppFilePaths::publicUrlForStoredPath('project_expenses/2026/05/proof.pdf'));
    }

    public function test_unknown_relative_disk_paths_are_private_by_default(): void
    {
        $this->assertPrivateFileUrl(AppFilePaths::publicUrlForStoredPath('client/files/contract.pdf'));
        $this->assertPrivateFileUrl(AppFilePaths::publicUrlForStoredPath('staff/files/profile.pdf'));
        $this->assertPrivateFileUrl(AppFilePaths::publicUrlForStoredPath('random-internal/file.pdf'));

        $this->assertFalse(AppFilePaths::isPublicRelativePath('client/files/contract.pdf'));
        $this->assertTrue(AppFilePaths::isSensitiveRelativePath('client/files/contract.pdf'));
    }

    public function test_configured_public_storage_urls_for_sensitive_paths_are_mapped_to_private_urls(): void
    {
        config(['filesystems.disks.public.url' => 'https://kijo.amiosh.com/storage']);

        $this->assertPrivateFileUrl(
            AppFilePaths::publicUrlForStoredPath('https://kijo.amiosh.com/storage/payments/2026/05/receipt.pdf')
        );
    }

    public function test_allowlisted_public_paths_stay_on_public_storage(): void
    {
        $this->assertSame('/storage/catalog/file.pdf', AppFilePaths::publicUrlForStoredPath('catalog/file.pdf'));
        $this->assertSame('/storage/whats-new/notice.pdf', AppFilePaths::publicUrlForStoredPath('whats-new/notice.pdf'));
        $this->assertSame('/storage/sport-time/banner.jpg', AppFilePaths::publicUrlForStoredPath('sport-time/banner.jpg'));
        $this->assertSame('/storage/sport-time/banner.jpg', AppFilePaths::publicUrlForStoredPath('/backend/uploads/sport-time/banner.jpg'));
    }

    public function test_public_storage_relative_paths_are_normalized_for_disk_reads(): void
    {
        $this->assertSame(
            'legacy-uploads/procedures/2026/file.pdf',
            AppFilePaths::publicStorageRelativePath('/storage/legacy-uploads/procedures/2026/file.pdf')
        );

        $this->assertSame(
            'legacy-uploads/procedures/2026/file.pdf',
            AppFilePaths::publicStorageRelativePath('/backend-legacy/uploads/procedures/2026/file.pdf')
        );

        $this->assertSame(
            'legacy-uploads/procedures/2026/file.pdf',
            AppFilePaths::publicStorageRelativePath('uploads/procedures/2026/file.pdf')
        );

        $this->assertSame(
            'sport-time/2026/banner.jpg',
            AppFilePaths::publicStorageRelativePath('/backend/uploads/sport-time/2026/banner.jpg')
        );

        $this->assertSame(
            'proposal-templates/special/9/file.pdf',
            AppFilePaths::publicStorageRelativePath('proposal-templates/special/9/file.pdf')
        );

        $this->assertNull(AppFilePaths::publicStorageRelativePath('https://example.com/file.pdf'));
        $this->assertNull(AppFilePaths::publicStorageRelativePath('/private/file.pdf'));
        $this->assertNull(AppFilePaths::publicStorageRelativePath('../file.pdf'));
    }

    private function assertPrivateFileUrl(string $url): void
    {
        $this->assertStringContainsString('/files/private/', $url);
        $this->assertMatchesRegularExpression('#/files/private/[A-Za-z0-9_-]+$#', $url);
    }
}
