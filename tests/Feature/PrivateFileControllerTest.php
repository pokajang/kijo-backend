<?php

namespace Tests\Feature;

use App\Support\AppFilePaths;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateFileControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');

        Schema::dropIfExists('system_users');
        Schema::create('system_users', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('staff_id');
            $table->string('email')->nullable();
            $table->text('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 10,
            'email' => 'staff@example.test',
            'role' => json_encode(['Staff']),
            'is_active' => 1,
        ]);
    }

    public function test_private_file_route_requires_authentication(): void
    {
        Storage::disk('private')->put('payments/2026/05/receipt.pdf', 'private receipt');

        $this->getJson($this->pathFor(AppFilePaths::privateFileUrlForStoredPath('payments/2026/05/receipt.pdf')))
            ->assertForbidden();
    }

    public function test_authenticated_user_can_download_private_file(): void
    {
        Storage::disk('private')->put('payments/2026/05/receipt.pdf', 'private receipt');

        $response = $this->actingSession()
            ->get($this->pathFor(AppFilePaths::privateFileUrlForStoredPath('payments/2026/05/receipt.pdf', 'receipt.pdf')));

        $response->assertOk();
        $this->assertSame('private receipt', $response->streamedContent());
    }

    public function test_private_file_route_rejects_tampered_token(): void
    {
        Storage::disk('private')->put('payments/2026/05/receipt.pdf', 'private receipt');
        $path = $this->pathFor(AppFilePaths::privateFileUrlForStoredPath('payments/2026/05/receipt.pdf'));
        $tamperedPath = str_replace('/files/private/', '/files/private/A', $path);

        $this->actingSession()->getJson($tamperedPath)->assertNotFound();
    }

    public function test_private_file_route_rejects_traversal_missing_files_and_public_paths(): void
    {
        Storage::disk('public')->put('catalog/public.pdf', 'public');

        $this->actingSession()->getJson('/files/private/'.$this->tokenFor('../payments/receipt.pdf'))->assertNotFound();
        $this->actingSession()->getJson('/files/private/'.$this->tokenFor('payments/missing.pdf'))->assertNotFound();
        $this->actingSession()->getJson('/files/private/'.$this->tokenFor('catalog/public.pdf'))->assertNotFound();
    }

    public function test_private_file_resolution_falls_back_to_public_until_migration_finishes(): void
    {
        Storage::disk('public')->put('payments/2026/05/legacy.pdf', 'legacy receipt');

        $response = $this->actingSession()
            ->get($this->pathFor(AppFilePaths::privateFileUrlForStoredPath('payments/2026/05/legacy.pdf', 'legacy.pdf')));

        $response->assertOk();
        $this->assertSame('legacy receipt', $response->streamedContent());
    }

    private function actingSession()
    {
        return $this->withSession([
            'user_id' => 1,
            'staff_id' => 10,
            'name_code' => 'STF',
        ]);
    }

    private function pathFor(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $privateRouteOffset = strpos($path, '/files/private/');

        return $privateRouteOffset === false ? $path : substr($path, $privateRouteOffset);
    }

    private function tokenFor(string $path): string
    {
        $payload = json_encode([
            'disk' => 'private',
            'path' => $path,
            'name' => basename($path),
        ], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(Crypt::encryptString($payload)), '+/', '-_'), '=');
    }
}
