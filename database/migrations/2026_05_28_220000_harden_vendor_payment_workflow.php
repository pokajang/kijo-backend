<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_payments')) {
            return;
        }

        Schema::table('vendor_payments', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'checked_by', fn () => $table->unsignedInteger('checked_by')->nullable());
            $this->addColumnIfMissing($table, 'checked_at', fn () => $table->timestamp('checked_at')->nullable());
            $this->addColumnIfMissing($table, 'checker_remarks', fn () => $table->text('checker_remarks')->nullable());
            $this->addColumnIfMissing($table, 'approval_remarks', fn () => $table->text('approval_remarks')->nullable());
            $this->addColumnIfMissing($table, 'returned_by', fn () => $table->unsignedInteger('returned_by')->nullable());
            $this->addColumnIfMissing($table, 'returned_at', fn () => $table->timestamp('returned_at')->nullable());
            $this->addColumnIfMissing($table, 'returned_remarks', fn () => $table->text('returned_remarks')->nullable());
            $this->addColumnIfMissing($table, 'rejected_by', fn () => $table->unsignedInteger('rejected_by')->nullable());
            $this->addColumnIfMissing($table, 'rejected_at', fn () => $table->timestamp('rejected_at')->nullable());
            $this->addColumnIfMissing($table, 'rejected_remarks', fn () => $table->text('rejected_remarks')->nullable());
            $this->addColumnIfMissing($table, 'paid_date', fn () => $table->date('paid_date')->nullable());
            $this->addColumnIfMissing($table, 'paid_amount', fn () => $table->decimal('paid_amount', 12, 2)->nullable());
            $this->addColumnIfMissing($table, 'paid_by', fn () => $table->unsignedInteger('paid_by')->nullable());
            $this->addColumnIfMissing($table, 'paid_at', fn () => $table->timestamp('paid_at')->nullable());
            $this->addColumnIfMissing($table, 'paid_remarks', fn () => $table->text('paid_remarks')->nullable());
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vendor_payments')) {
            return;
        }

        Schema::table('vendor_payments', function (Blueprint $table): void {
            foreach ([
                'checked_by',
                'checked_at',
                'checker_remarks',
                'approval_remarks',
                'returned_by',
                'returned_at',
                'returned_remarks',
                'rejected_by',
                'rejected_at',
                'rejected_remarks',
                'paid_date',
                'paid_amount',
                'paid_by',
                'paid_at',
                'paid_remarks',
            ] as $column) {
                if (Schema::hasColumn('vendor_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn('vendor_payments', $column)) {
            $definition();
        }
    }
};
