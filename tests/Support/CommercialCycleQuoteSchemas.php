<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class CommercialCycleQuoteSchemas
{
    public static function replace(string $service): void
    {
        CommercialCycleDatabaseGuard::assertSqliteMemory();

        match ($service) {
            'training' => self::createTraining(),
            'equipment' => self::createEquipment(),
            'manpower' => self::createManpower(),
            'special' => self::createSpecial(),
            default => throw new InvalidArgumentException("Unsupported quote service: {$service}"),
        };
    }

    private static function createTraining(): void
    {
        Schema::dropIfExists('quotes_training');
        Schema::create('quotes_training', function (Blueprint $table): void {
            self::commonColumns($table);
            $table->boolean('attach_proposal')->default(false);
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->unsignedBigInteger('training_id')->nullable();
            $table->string('training_title')->nullable();
            $table->string('training_type')->nullable();
            $table->string('payment_method')->nullable();
            $table->date('proposed_date')->nullable();
            $table->date('proposed_end_date')->nullable();
            $table->boolean('to_be_confirmed')->default(false);
            $table->string('venue')->nullable();
            $table->text('remarks')->nullable();
            $table->text('target_groups')->nullable();
            $table->unsignedInteger('pax')->nullable();
            $table->unsignedInteger('session_count')->nullable();
            $table->decimal('duration_per_session', 12, 2)->nullable();
            $table->string('duration_unit')->nullable();
            $table->string('pricing_basis')->nullable();
            $table->string('training_rate_type')->nullable();
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('travel_charge', 15, 2)->nullable();
            $table->string('travel_region')->nullable();
            $table->unsignedBigInteger('price_exception_request_id')->nullable();
            $table->string('meals_provided')->nullable();
            $table->decimal('meal_price', 15, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('sst_rate', 8, 2)->nullable();
            $table->decimal('hrd_charge', 8, 2)->nullable();
            foreach ([
                'training_total', 'meal_total', 'mobilization_cost', 'discount_amount',
                'subtotal', 'sst_amount', 'hrd_amount', 'grand_total', 'estimated_total_cost',
            ] as $column) {
                $table->decimal($column, 15, 2)->nullable();
            }
            $table->string('traffic_light_rule_version', 80)->nullable();
        });
    }

    private static function createEquipment(): void
    {
        Schema::dropIfExists('quotes_equipment_items');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('quotes_equipment');

        Schema::create('quotes_equipment', function (Blueprint $table): void {
            self::commonColumns($table);
            $table->text('inquiry_remarks')->nullable();
            $table->text('quotation_remarks')->nullable();
            foreach (['discount', 'delivery_charge', 'misc_charge', 'sst_percent', 'sst_amount', 'sub_total', 'grand_total', 'estimated_total_cost'] as $column) {
                $table->decimal($column, 15, 2)->nullable();
            }
            $table->string('traffic_light_rule_version', 80)->nullable();
            $table->boolean('attach_proposal')->default(false);
            $table->unsignedBigInteger('price_exception_request_id')->nullable();
        });

        Schema::create('catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('item_name');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->string('supplier_name')->nullable();
            $table->decimal('supplier_price', 15, 2)->nullable();
            $table->date('price_date')->nullable();
            $table->text('remarks')->nullable();
            $table->string('brochure_filename')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_code')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_code')->nullable();
            $table->timestamps();
        });
        Schema::create('quotes_equipment_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('item_id');
            $table->text('item_remarks')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('marked_up_price', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        DB::table('catalog_items')->insert([
            'id' => 701,
            'item_name' => 'Gas detector',
            'description' => 'Portable calibrated gas detector.',
            'unit' => 'unit',
            'supplier_name' => 'Laboratory Supplier',
            'supplier_price' => 700,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function createManpower(): void
    {
        Schema::dropIfExists('quotes_manpower');
        Schema::create('quotes_manpower', function (Blueprint $table): void {
            self::commonColumns($table);
            $table->unsignedBigInteger('mp_id')->nullable();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->string('manpower_rate_type')->nullable();
            $table->string('billing_unit')->nullable();
            $table->text('nature_of_work')->nullable();
            $table->string('site_location')->nullable();
            $table->decimal('duration_months', 12, 2)->nullable();
            $table->decimal('duration_hours', 12, 2)->nullable();
            $table->boolean('requires_management_approval')->default(false);
            $table->unsignedBigInteger('price_exception_request_id')->nullable();
            $table->decimal('base_unit_cost', 15, 2)->nullable();
            $table->decimal('approved_unit_cost_floor', 15, 2)->nullable();
            $table->decimal('approved_discount_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('price_exception_approved_by')->nullable();
            $table->timestamp('price_exception_approved_at')->nullable();
            $table->unsignedInteger('no_of_pax')->nullable();
            foreach (['unit_cost', 'discount', 'sst_percent', 'sst_amount', 'sub_total', 'grand_total', 'estimated_total_cost'] as $column) {
                $table->decimal($column, 15, 2)->nullable();
            }
            $table->text('inquiry_remarks')->nullable();
            $table->boolean('attach_proposal')->default(false);
            $table->string('traffic_light_rule_version', 80)->nullable();
        });
    }

    private static function createSpecial(): void
    {
        Schema::dropIfExists('quotes_special_proposal_snapshots');
        Schema::dropIfExists('quotes_special_items');
        Schema::dropIfExists('quotes_special');
        Schema::dropIfExists('proposal_template_special');
        Schema::create('proposal_template_special', function (Blueprint $table): void {
            $table->id();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->string('proposal_language', 10)->default('en');
            $table->string('proposal_mode', 20)->default('write');
            $table->longText('service_summary')->nullable();
            $table->longText('proposal_content')->nullable();
            $table->longText('content')->nullable();
            $table->string('translation_status')->nullable();
            $table->integer('is_deleted')->default(0);
            $table->timestamps();
        });
        Schema::create('quotes_special', function (Blueprint $table): void {
            self::commonColumns($table);
            $table->unsignedBigInteger('sp_id')->nullable();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->text('general_remarks')->nullable();
            $table->text('inquiry_remarks')->nullable();
            foreach (['unit_cost', 'discount', 'sst_percent', 'sst_amount', 'sub_total', 'grand_total'] as $column) {
                $table->decimal($column, 15, 2)->nullable();
            }
            $table->unsignedBigInteger('price_exception_request_id')->nullable();
            $table->boolean('attach_proposal')->default(false);
        });
        Schema::create('quotes_special_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('service_id')->nullable();
            $table->string('line_item_title');
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('quantity', 12, 2);
            $table->decimal('line_total', 15, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('quotes_special_proposal_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('template_id');
            $table->string('proposal_language')->nullable();
            $table->string('proposal_mode')->nullable();
            $table->string('service_title')->nullable();
            $table->string('service_code')->nullable();
            $table->text('service_summary')->nullable();
            $table->longText('proposal_content')->nullable();
            $table->json('attachments_json')->nullable();
            $table->timestamp('template_updated_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });

        DB::table('proposal_template_special')->insert([
            'id' => 501,
            'service_title' => 'Special Compliance Review',
            'service_code' => 'SCR',
            'proposal_language' => 'en',
            'proposal_mode' => 'write',
            'proposal_content' => '<p>Special compliance review proposal.</p>',
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function commonColumns(Blueprint $table): void
    {
        $table->id();
        $table->string('service_group')->nullable();
        $table->unsignedInteger('quote_running_no')->nullable();
        $table->string('quote_ref_no')->nullable();
        $table->unsignedInteger('revision_no')->default(0);
        $table->string('status')->nullable();
        $table->text('status_remarks')->nullable();
        $table->date('award_date')->nullable();
        $table->string('client_award_ref_no')->nullable();
        $table->unsignedBigInteger('created_by_id')->nullable();
        $table->string('created_by_name')->nullable();
        $table->string('created_by_code')->nullable();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->string('client_name')->nullable();
        $table->string('client_ssm')->nullable();
        $table->string('client_address')->nullable();
        $table->string('client_city')->nullable();
        $table->string('client_state')->nullable();
        $table->string('client_zip')->nullable();
        $table->text('pic_name')->nullable();
        $table->text('pic_email')->nullable();
        $table->text('pic_phone')->nullable();
        $table->text('pic_position')->nullable();
        $table->string('proposal_language')->nullable();
        $table->unsignedBigInteger('approval_request_id')->nullable();
        $table->string('approval_zone')->nullable();
        $table->string('approval_status')->nullable();
        $table->string('approval_fingerprint', 64)->nullable();
        $table->timestamps();
    }
}
