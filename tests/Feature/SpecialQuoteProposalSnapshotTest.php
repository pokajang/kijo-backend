<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpecialQuoteProposalSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($string, $delimiter, $count) {
            $parts = explode((string) $delimiter, (string) $string);
            if ((int) $count < 0) {
                return implode($delimiter, array_slice($parts, (int) $count));
            }

            return implode($delimiter, array_slice($parts, 0, (int) $count));
        }, 3);

        $this->createSchema();
    }

    public function test_special_quote_create_captures_upload_proposal_snapshot(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('proposal-templates/special/10/source.pdf', '%PDF source');

        DB::table('proposal_template_special')->insert([
            'id' => 10,
            'service_title' => 'Special Proposal',
            'service_code' => 'SP',
            'proposal_language' => 'en',
            'proposal_mode' => 'upload',
            'service_summary' => '<p>Internal</p>',
            'proposal_content' => '',
            'content' => '<p>Internal</p>',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('proposal_special_attachments')->insert([
            'id' => 50,
            'template_id' => 10,
            'original_filename' => 'source.pdf',
            'stored_path' => 'proposal-templates/special/10/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 20,
            'created_at' => now(),
        ]);

        $response = $this->authenticated()
            ->postJson('/quotes/special', $this->quotePayload())
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $quoteId = (int) $response->json('quote_id');
        $snapshot = DB::table('quotes_special_proposal_snapshots')->where('quote_id', $quoteId)->first();

        $this->assertNotNull($snapshot);
        $this->assertSame(10, (int) $snapshot->template_id);
        $this->assertSame('upload', $snapshot->proposal_mode);
        $attachments = json_decode($snapshot->attachments_json, true);
        $this->assertCount(1, $attachments);
        $this->assertStringStartsWith('quote-proposals/special/'.$quoteId.'/', $attachments[0]['storedPath']);
        Storage::disk('private')->assertExists($attachments[0]['storedPath']);
    }

    public function test_special_quote_rejects_language_mismatch(): void
    {
        DB::table('proposal_template_special')->insert([
            'id' => 10,
            'service_title' => 'Special Proposal',
            'service_code' => 'SP',
            'proposal_language' => 'ms-MY',
            'proposal_mode' => 'write',
            'proposal_content' => '<p>BM Content</p>',
            'content' => '<p>BM Content</p>',
            'is_deleted' => 0,
        ]);

        $this->authenticated()
            ->postJson('/quotes/special', $this->quotePayload())
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_special_quote_recalculates_decimal_line_totals_server_side(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('proposal-templates/special/10/source.pdf', '%PDF source');

        DB::table('proposal_template_special')->insert([
            'id' => 10,
            'service_title' => 'Special Proposal',
            'service_code' => 'SP',
            'proposal_language' => 'en',
            'proposal_mode' => 'upload',
            'is_deleted' => 0,
        ]);
        DB::table('proposal_special_attachments')->insert([
            'template_id' => 10,
            'original_filename' => 'source.pdf',
            'stored_path' => 'proposal-templates/special/10/source.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->authenticated()
            ->postJson('/quotes/special', $this->quotePayload([
                'line_items' => [
                    [
                        'item_name' => 'Decimal Audit',
                        'quantity' => 1.5,
                        'unit_price' => 80,
                        'total_price' => 1,
                    ],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $item = DB::table('quotes_special_items')->where('line_item_title', 'Decimal Audit')->first();

        $this->assertSame(1.5, (float) $item->quantity);
        $this->assertSame(120.0, (float) $item->line_total);
    }

    public function test_special_quote_snapshot_copy_failure_rolls_back_quote_save(): void
    {
        Storage::fake('private');

        DB::table('proposal_template_special')->insert([
            'id' => 10,
            'service_title' => 'Special Proposal',
            'service_code' => 'SP',
            'proposal_language' => 'en',
            'proposal_mode' => 'upload',
            'is_deleted' => 0,
        ]);
        DB::table('proposal_special_attachments')->insert([
            'template_id' => 10,
            'original_filename' => 'missing.pdf',
            'stored_path' => 'proposal-templates/special/10/missing.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->authenticated()
            ->postJson('/quotes/special', $this->quotePayload())
            ->assertStatus(500)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('quotes_special', 0);
        $this->assertDatabaseCount('quotes_special_proposal_snapshots', 0);
    }

    private function createSchema(): void
    {
        foreach ([
            'quotes_special_proposal_snapshots',
            'quotes_special_items',
            'quotes_special',
            'proposal_special_attachments',
            'proposal_template_special',
            'quote_price_exception_requests',
            'projects_main',
            'user_activities',
            'staff_general',
            'system_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('proposal_template_special', function (Blueprint $table): void {
            $table->id();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->string('proposal_language', 10)->default('en');
            $table->string('proposal_mode', 20)->default('upload');
            $table->longText('service_summary')->nullable();
            $table->longText('proposal_content')->nullable();
            $table->longText('content')->nullable();
            $table->string('translation_status')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('proposal_special_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('quotes_special', function (Blueprint $table): void {
            $table->id();
            foreach ([
                'service_group', 'quote_running_no', 'quote_ref_no', 'client_id', 'client_name',
                'client_ssm', 'client_address', 'client_city', 'client_state', 'client_zip',
                'pic_name', 'pic_email', 'pic_phone', 'pic_position', 'sp_id', 'service_title',
                'service_code', 'general_remarks', 'sst_percent', 'sst_amount', 'sub_total',
                'grand_total', 'attach_proposal', 'status', 'revision_no', 'created_by_id',
                'created_by_name', 'created_by_code', 'proposal_language',
            ] as $column) {
                $table->text($column)->nullable();
            }
            $table->decimal('discount', 12, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('quotes_special_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('line_item_title')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('line_total', 12, 2)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('quotes_special_proposal_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id')->unique();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('proposal_language', 10)->default('en');
            $table->string('proposal_mode', 20)->default('upload');
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->longText('service_summary')->nullable();
            $table->longText('proposal_content')->nullable();
            $table->json('attachments_json')->nullable();
            $table->timestamp('template_updated_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->id();
            $table->integer('staff_id');
            $table->string('name_code', 20);
            $table->text('action');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('system_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('email');
            $table->json('role')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('staff_general', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('full_name')->nullable();
            $table->string('name_code')->nullable();
        });

        DB::table('system_users')->insert([
            'id' => 1,
            'staff_id' => 7,
            'email' => 'sysadmin@example.test',
            'role' => json_encode(['System Admin']),
            'is_active' => 1,
        ]);
        DB::table('staff_general')->insert([
            'staff_id' => 7,
            'full_name' => 'QA User',
            'name_code' => 'QA',
        ]);
    }

    private function quotePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'client_id' => 1,
            'client_name' => 'Client',
            'client_ssm' => '',
            'client_address' => '1 Test Road',
            'client_city' => '',
            'client_state' => '',
            'client_zip' => '',
            'pic_name' => 'PIC',
            'pic_email' => 'pic@example.test',
            'pic_phone' => '60123456789',
            'pic_position' => 'Manager',
            'sp_id' => 10,
            'service_title' => 'Special Proposal',
            'service_code' => 'SP',
            'general_remarks' => '',
            'discount' => 0,
            'sst_percent' => 0,
            'attach_proposal' => 1,
            'proposal_language' => 'en',
            'line_items' => [
                [
                    'item_name' => 'Audit',
                    'description' => '',
                    'unit' => 'Day',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'total_price' => 100,
                ],
            ],
        ], $overrides);
    }

    private function authenticated(): self
    {
        return $this
            ->withSession([
                '_token' => 'test-csrf-token',
                'user_id' => 1,
                'staff_id' => 7,
                'name_code' => 'QA',
                'roles' => ['System Admin'],
                'full_name' => 'QA User',
            ])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf-token');
    }
}
