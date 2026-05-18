<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_debtors', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_ref_no', 191)->unique();
            $table->string('client_name', 191);
            $table->string('pic_name', 191)->nullable();
            $table->string('pic_phone', 80)->nullable();
            $table->string('pic_email', 191)->nullable();
            $table->string('service_type', 120)->nullable();
            $table->string('service_period', 191)->nullable();
            $table->text('purpose')->nullable();
            $table->date('invoice_date');
            $table->decimal('grand_total', 15, 2);
            $table->string('status', 40)->default('Open');
            $table->string('payment_method', 120)->nullable();
            $table->date('paid_date')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->text('paid_remarks')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_code', 40)->nullable();
            $table->timestamps();

            $table->index(['status', 'invoice_date'], 'manual_debtors_status_invoice_date_idx');
            $table->index(['client_name', 'invoice_date'], 'manual_debtors_client_invoice_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_debtors');
    }
};
