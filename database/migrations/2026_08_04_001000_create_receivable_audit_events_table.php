<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('invoice_ref_no', 191)->nullable();
            $table->string('event_type', 40);
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->string('actor_code', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['source_type', 'source_id', 'created_at'],
                'receivable_audit_source_created_idx',
            );
            $table->index(['event_type', 'created_at'], 'receivable_audit_event_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_audit_events');
    }
};
