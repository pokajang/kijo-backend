<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('quote_price_exception_requests')) {
            Schema::create('quote_price_exception_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('service_group', 50)->default('manpower')->index();
                $table->unsignedBigInteger('quote_id')->index();
                $table->string('quote_ref_no', 100)->nullable();
                $table->unsignedInteger('revision_no_at_request')->default(0);
                $table->unsignedBigInteger('requested_by_id')->nullable()->index();
                $table->string('requested_by_name', 255)->nullable();
                $table->string('requested_by_code', 50)->nullable();
                $table->decimal('base_unit_cost', 12, 2);
                $table->decimal('current_unit_cost', 12, 2);
                $table->decimal('requested_unit_cost', 12, 2);
                $table->decimal('requested_discount_amount', 12, 2);
                $table->decimal('requested_discount_percent', 8, 4)->default(0);
                $table->decimal('approved_unit_cost_floor', 12, 2)->nullable();
                $table->decimal('approved_discount_amount', 12, 2)->nullable();
                $table->decimal('approved_discount_percent', 8, 4)->nullable();
                $table->text('client_negotiation_reason')->nullable();
                $table->text('requester_remarks')->nullable();
                $table->text('approval_remarks')->nullable();
                $table->string('status', 30)->default('pending')->index();
                $table->unsignedBigInteger('approved_by_id')->nullable()->index();
                $table->string('approved_by_name', 255)->nullable();
                $table->string('approved_by_code', 50)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('used_revision_quote_id')->nullable()->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();

                $table->index(['service_group', 'quote_id', 'status'], 'qper_service_quote_status_idx');
            });
        }

        Schema::table('quotes_manpower', function (Blueprint $table): void {
            if (!Schema::hasColumn('quotes_manpower', 'price_exception_request_id')) {
                $table->unsignedBigInteger('price_exception_request_id')->nullable()->after('requires_management_approval')->index();
            }
            if (!Schema::hasColumn('quotes_manpower', 'base_unit_cost')) {
                $table->decimal('base_unit_cost', 12, 2)->nullable()->after('price_exception_request_id');
            }
            if (!Schema::hasColumn('quotes_manpower', 'approved_unit_cost_floor')) {
                $table->decimal('approved_unit_cost_floor', 12, 2)->nullable()->after('base_unit_cost');
            }
            if (!Schema::hasColumn('quotes_manpower', 'approved_discount_amount')) {
                $table->decimal('approved_discount_amount', 12, 2)->nullable()->after('approved_unit_cost_floor');
            }
            if (!Schema::hasColumn('quotes_manpower', 'price_exception_approved_by')) {
                $table->string('price_exception_approved_by', 255)->nullable()->after('approved_discount_amount');
            }
            if (!Schema::hasColumn('quotes_manpower', 'price_exception_approved_at')) {
                $table->timestamp('price_exception_approved_at')->nullable()->after('price_exception_approved_by');
            }
        });
    }

    public function down(): void
    {
        foreach ([
            'price_exception_approved_at',
            'price_exception_approved_by',
            'approved_discount_amount',
            'approved_unit_cost_floor',
            'base_unit_cost',
            'price_exception_request_id',
        ] as $column) {
            if (Schema::hasColumn('quotes_manpower', $column)) {
                Schema::table('quotes_manpower', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        Schema::dropIfExists('quote_price_exception_requests');
    }
};
