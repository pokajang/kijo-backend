<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quote_price_exception_requests')) {
            Schema::table('quote_price_exception_requests', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'request_type', fn () => $table->string('request_type', 30)->default('quote')->after('service_group')->index());
                $this->addColumnIfMissing($table, 'current_total_amount', fn () => $table->decimal('current_total_amount', 12, 2)->nullable()->after('requested_discount_percent'));
                $this->addColumnIfMissing($table, 'requested_final_total', fn () => $table->decimal('requested_final_total', 12, 2)->nullable()->after('current_total_amount'));
                $this->addColumnIfMissing($table, 'approved_final_total', fn () => $table->decimal('approved_final_total', 12, 2)->nullable()->after('approved_discount_percent'));
                $this->addColumnIfMissing($table, 'request_payload', fn () => $table->json('request_payload')->nullable()->after('approval_remarks'));
                $this->addColumnIfMissing($table, 'decision_email_sent_at', fn () => $table->timestamp('decision_email_sent_at')->nullable()->after('used_at'));
                $this->addColumnIfMissing($table, 'request_email_sent_at', fn () => $table->timestamp('request_email_sent_at')->nullable()->after('decision_email_sent_at'));
            });
        }

        if (Schema::hasTable('quotes_training')) {
            Schema::table('quotes_training', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'pricing_basis', fn () => $table->string('pricing_basis', 30)->nullable()->after('duration_unit'));
                $this->addColumnIfMissing($table, 'training_rate_type', fn () => $table->string('training_rate_type', 80)->nullable()->after('pricing_basis')->index());
                $this->addColumnIfMissing($table, 'travel_region', fn () => $table->string('travel_region', 80)->nullable()->after('travel_charge'));
                $this->addColumnIfMissing($table, 'price_exception_request_id', fn () => $table->unsignedBigInteger('price_exception_request_id')->nullable()->after('travel_region')->index());
            });
        }

        if (Schema::hasTable('quotes_special')) {
            Schema::table('quotes_special', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'discount', fn () => $table->decimal('discount', 15, 2)->default(0)->after('general_remarks'));
                $this->addColumnIfMissing($table, 'price_exception_request_id', fn () => $table->unsignedBigInteger('price_exception_request_id')->nullable()->after('discount')->index());
            });
        }

        foreach (['quotes_ih', 'quotes_equipment'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'price_exception_request_id')) {
                    $table->unsignedBigInteger('price_exception_request_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'quotes_equipment' => ['price_exception_request_id'],
            'quotes_ih' => ['price_exception_request_id'],
            'quotes_special' => ['price_exception_request_id', 'discount'],
            'quotes_training' => ['price_exception_request_id', 'travel_region', 'training_rate_type', 'pricing_basis'],
            'quote_price_exception_requests' => [
                'request_email_sent_at',
                'decision_email_sent_at',
                'request_payload',
                'approved_final_total',
                'requested_final_total',
                'current_total_amount',
                'request_type',
            ],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $definition): void
    {
        if (!Schema::hasColumn($table->getTable(), $column)) {
            $definition();
        }
    }
};
