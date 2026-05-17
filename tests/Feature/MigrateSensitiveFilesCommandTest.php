<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrateSensitiveFilesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
    }

    public function test_dry_run_reports_private_candidates_without_moving_files(): void
    {
        $this->seedFiles();

        $this->artisan('app:migrate-sensitive-files')
            ->expectsOutput('Public allowlisted files skipped: 3.')
            ->expectsOutput('Private candidates found: 3.')
            ->expectsOutput('Already private: 0.')
            ->expectsOutput('Dry run only. Re-run with --commit to move files.')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('catalog/file.pdf');
        Storage::disk('public')->assertExists('whats-new/notice.pdf');
        Storage::disk('public')->assertExists('sport-time/banner.jpg');
        Storage::disk('public')->assertExists('client/files/contract.pdf');
        Storage::disk('public')->assertExists('staff/files/profile.pdf');
        Storage::disk('public')->assertExists('random-internal/file.pdf');

        Storage::disk('private')->assertMissing('client/files/contract.pdf');
        Storage::disk('private')->assertMissing('staff/files/profile.pdf');
        Storage::disk('private')->assertMissing('random-internal/file.pdf');
    }

    public function test_commit_moves_every_non_allowlisted_file_private_by_default(): void
    {
        $this->seedFiles();

        $this->artisan('app:migrate-sensitive-files --commit')
            ->expectsOutput('Public allowlisted files skipped: 3.')
            ->expectsOutput('Private candidates found: 3.')
            ->expectsOutput('Already private: 0.')
            ->expectsOutput('Moved to private: 3.')
            ->assertExitCode(0);

        Storage::disk('public')->assertExists('catalog/file.pdf');
        Storage::disk('public')->assertExists('whats-new/notice.pdf');
        Storage::disk('public')->assertExists('sport-time/banner.jpg');

        Storage::disk('public')->assertMissing('client/files/contract.pdf');
        Storage::disk('public')->assertMissing('staff/files/profile.pdf');
        Storage::disk('public')->assertMissing('random-internal/file.pdf');

        Storage::disk('private')->assertExists('client/files/contract.pdf');
        Storage::disk('private')->assertExists('staff/files/profile.pdf');
        Storage::disk('private')->assertExists('random-internal/file.pdf');
    }

    private function seedFiles(): void
    {
        Storage::disk('public')->put('catalog/file.pdf', 'catalog');
        Storage::disk('public')->put('whats-new/notice.pdf', 'notice');
        Storage::disk('public')->put('sport-time/banner.jpg', 'banner');
        Storage::disk('public')->put('client/files/contract.pdf', 'contract');
        Storage::disk('public')->put('staff/files/profile.pdf', 'profile');
        Storage::disk('public')->put('random-internal/file.pdf', 'internal');
    }
}
