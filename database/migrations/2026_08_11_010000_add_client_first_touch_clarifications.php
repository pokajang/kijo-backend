<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_first_touch_conflicts', function (Blueprint $table): void {
            $table->unsignedBigInteger('clarification_recipient_staff_id')
                ->nullable()
                ->after('clarification_recipient')
                ->index('client_first_touch_conflict_clarification_staff_idx');
        });

        Schema::create('client_first_touch_clarifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conflict_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('requested_from_staff_id');
            $table->string('requested_from_name', 255);
            $table->unsignedBigInteger('requested_by_staff_id');
            $table->string('requested_by_name', 255);
            $table->text('request_note');
            $table->string('status', 32)->default('pending');
            $table->text('response')->nullable();
            $table->unsignedBigInteger('responded_by_staff_id')->nullable();
            $table->string('responded_by_name', 255)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['conflict_id', 'status'], 'client_first_touch_clarification_status_idx');
            $table->index(
                ['requested_from_staff_id', 'status'],
                'client_first_touch_clarification_recipient_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_first_touch_clarifications');

        Schema::table('client_first_touch_conflicts', function (Blueprint $table): void {
            $table->dropIndex('client_first_touch_conflict_clarification_staff_idx');
            $table->dropColumn('clarification_recipient_staff_id');
        });
    }
};
