<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 191);
            $table->string('ssm_number', 80)->nullable();
            $table->string('tax_id_no_tin', 80)->nullable();
            $table->string('contact_name', 191)->nullable();
            $table->string('mobile', 80)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('zip', 30)->nullable();
            $table->string('service_required', 80)->nullable();
            $table->string('source', 100);
            $table->string('source_remarks', 500)->nullable();
            $table->date('inquiry_date');
            $table->string('status', 40)->default('new');
            $table->text('remarks')->nullable();
            $table->string('proof_path', 255)->nullable();
            $table->string('proof_original_name', 191)->nullable();
            $table->string('proof_mime_type', 100)->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name', 191)->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('quote_ref_no', 80)->nullable();
            $table->string('quote_service_type', 40)->nullable();
            $table->unsignedBigInteger('owner_staff_id')->nullable();
            $table->string('owner_staff_code', 32)->nullable();
            $table->string('owner_staff_name', 191)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_code', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inquiry_date', 'status'], 'sales_inquiries_date_status_idx');
            $table->index(['status', 'service_required'], 'sales_inquiries_status_service_idx');
            $table->index(['client_id'], 'sales_inquiries_client_idx');
            $table->index(['quote_id', 'quote_service_type'], 'sales_inquiries_quote_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_inquiries');
    }
};
