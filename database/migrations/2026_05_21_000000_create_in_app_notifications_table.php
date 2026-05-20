<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('in_app_notifications')) {
            Schema::table('in_app_notifications', function (Blueprint $table): void {
                $table->string('module_key', 80)->change();
                $table->string('entity_type', 80)->change();
                $table->string('type', 80)->change();
            });

            $this->ensureIndexes();

            return;
        }

        Schema::create('in_app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('recipient_staff_id')->index();
            $table->unsignedInteger('actor_staff_id')->nullable()->index();
            $table->string('module_key', 80)->index();
            $table->string('entity_type', 80)->index();
            $table->unsignedBigInteger('entity_id')->index();
            $table->string('type', 80)->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('route')->nullable();
            $table->string('severity', 40)->default('info');
            $table->json('metadata_json')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(
                ['recipient_staff_id', 'module_key', 'entity_type', 'entity_id'],
                'in_app_notifications_recipient_entity_idx',
            );
            $table->index(
                ['module_key', 'entity_type', 'entity_id', 'type'],
                'in_app_notifications_entity_type_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }

    private function ensureIndexes(): void
    {
        if (!Schema::hasIndex('in_app_notifications', 'in_app_notifications_recipient_entity_idx')) {
            Schema::table('in_app_notifications', function (Blueprint $table): void {
                $table->index(
                    ['recipient_staff_id', 'module_key', 'entity_type', 'entity_id'],
                    'in_app_notifications_recipient_entity_idx',
                );
            });
        }

        if (!Schema::hasIndex('in_app_notifications', 'in_app_notifications_entity_type_idx')) {
            Schema::table('in_app_notifications', function (Blueprint $table): void {
                $table->index(
                    ['module_key', 'entity_type', 'entity_id', 'type'],
                    'in_app_notifications_entity_type_idx',
                );
            });
        }
    }
};
