<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireAuth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientCompanyUpdateFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([RequireAuth::class]);
        $this->createLegacyClientTables();
    }

    public function test_client_company_update_supports_legacy_schema_without_updated_at(): void
    {
        DB::table('client_company')->insert([
            'company_id' => 122,
            'company_name' => 'Old Client',
            'ssm_number' => 'OLD-SSM',
            'tax_id_no_tin' => null,
            'client_status' => 'New',
            'payment_terms_days' => null,
            'address' => 'Old address',
            'city' => 'Old city',
            'state' => 'Selangor',
            'zip' => '43000',
            'created_at' => now(),
            'status' => 'active',
            'deleted_at' => null,
            'deleted_by' => null,
        ]);

        DB::table('client_pic')->insert([
            'pic_id' => 166,
            'full_name' => 'Old PIC',
            'email' => 'old@example.test',
            'mobile_number' => '601',
            'position' => 'Admin',
            'company_id' => 122,
            'created_at' => now(),
            'status' => 'assigned',
            'deleted_at' => null,
        ]);

        $response = $this->putJson('/client-companies/122', [
            'companyName' => 'Updated Client',
            'ssmNumber' => 'NEW-SSM',
            'taxIdNoTin' => 'TIN-1',
            'clientStatus' => 'Old',
            'useDefaultPaymentTerms' => false,
            'paymentTermsDays' => 45,
            'address' => 'Updated address',
            'city' => 'Kajang',
            'state' => 'Selangor',
            'zip' => '43001',
            'country' => 'Malaysia',
            'picList' => [[
                'pic_id' => 166,
                'full_name' => 'Updated PIC',
                'email' => 'updated@example.test',
                'mobile_number' => '602',
                'position' => 'Manager',
            ]],
            'newPicList' => [],
            'branchList' => [],
        ]);

        $response
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('client_company', [
            'company_id' => 122,
            'company_name' => 'Updated Client',
            'ssm_number' => 'NEW-SSM',
            'payment_terms_days' => 45,
        ]);

        $this->assertDatabaseHas('client_pic', [
            'pic_id' => 166,
            'full_name' => 'Updated PIC',
            'email' => 'updated@example.test',
            'company_id' => 122,
            'status' => 'assigned',
        ]);
    }

    private function createLegacyClientTables(): void
    {
        Schema::dropIfExists('client_company_branch');
        Schema::dropIfExists('client_pic');
        Schema::dropIfExists('client_company');

        Schema::create('client_company', function (Blueprint $table): void {
            $table->integer('company_id')->primary();
            $table->string('company_name')->nullable();
            $table->string('ssm_number')->nullable();
            $table->string('tax_id_no_tin')->nullable();
            $table->string('client_status')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
        });

        Schema::create('client_pic', function (Blueprint $table): void {
            $table->integer('pic_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile_number')->nullable();
            $table->string('position')->nullable();
            $table->integer('company_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });

        Schema::create('client_company_branch', function (Blueprint $table): void {
            $table->integer('branch_id')->primary();
            $table->integer('company_id')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->integer('deleted_by')->nullable();
        });
    }
}
