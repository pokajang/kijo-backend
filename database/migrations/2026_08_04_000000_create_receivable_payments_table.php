<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 15, 2);
            $table->date('payment_date');
            $table->string('payment_method', 120)->nullable();
            $table->string('transaction_reference', 191)->nullable();
            $table->text('remarks')->nullable();
            $table->uuid('request_token')->unique();
            $table->unsignedBigInteger('recorded_by_staff_id')->nullable();
            $table->string('recorded_by_code', 40)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_staff_id')->nullable();
            $table->string('reversed_by_code', 40)->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->index(
                ['source_type', 'source_id', 'payment_date'],
                'receivable_payments_source_date_idx',
            );
            $table->index(
                ['source_type', 'source_id', 'reversed_at'],
                'receivable_payments_source_active_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
