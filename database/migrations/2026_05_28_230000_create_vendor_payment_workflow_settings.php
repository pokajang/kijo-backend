<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_payment_workflow_settings')) {
            Schema::create('vendor_payment_workflow_settings', function (Blueprint $table): void {
                $table->string('setting_key')->primary();
                $table->text('setting_value')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vendor_payment_workflow_recipients')) {
            Schema::create('vendor_payment_workflow_recipients', function (Blueprint $table): void {
                $table->id();
                $table->string('stage_type', 20);
                $table->unsignedTinyInteger('level_no')->default(1);
                $table->unsignedBigInteger('staff_id');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['stage_type', 'level_no', 'staff_id'], 'vendor_payment_workflow_recipient_unique');
            });
        }

        if (Schema::hasTable('vendor_payments')) {
            Schema::table('vendor_payments', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'current_review_level', fn () => $table->unsignedTinyInteger('current_review_level')->nullable());
                $this->addColumnIfMissing($table, 'current_approval_level', fn () => $table->unsignedTinyInteger('current_approval_level')->nullable());
                $this->addColumnIfMissing($table, 'workflow_progress_json', fn () => $table->json('workflow_progress_json')->nullable());
                $this->addColumnIfMissing($table, 'workflow_settings_snapshot_json', fn () => $table->json('workflow_settings_snapshot_json')->nullable());
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_payments')) {
            Schema::table('vendor_payments', function (Blueprint $table): void {
                foreach ([
                    'current_review_level',
                    'current_approval_level',
                    'workflow_progress_json',
                    'workflow_settings_snapshot_json',
                ] as $column) {
                    if (Schema::hasColumn('vendor_payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('vendor_payment_workflow_recipients');
        Schema::dropIfExists('vendor_payment_workflow_settings');
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn('vendor_payments', $column)) {
            $definition();
        }
    }
};
