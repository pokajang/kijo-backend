<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_payments')) {
            Schema::table('vendor_payments', function (Blueprint $table): void {
                $this->add($table, 'version', fn () => $table->unsignedInteger('version')->default(1));
                $this->add($table, 'idempotency_key', fn () => $table->string('idempotency_key', 120)->nullable());
                $this->add($table, 'project_vendor_assignment_id', fn () => $table->unsignedBigInteger('project_vendor_assignment_id')->nullable());
                $this->add($table, 'parent_payment_id', fn () => $table->unsignedBigInteger('parent_payment_id')->nullable());
                $this->add($table, 'revision_number', fn () => $table->unsignedInteger('revision_number')->default(1));
                $this->add($table, 'superseded_by_payment_id', fn () => $table->unsignedBigInteger('superseded_by_payment_id')->nullable());
                $this->add($table, 'superseded_at', fn () => $table->timestamp('superseded_at')->nullable());
                $this->add($table, 'cancelled_at', fn () => $table->timestamp('cancelled_at')->nullable());
                $this->add($table, 'cancelled_by', fn () => $table->unsignedBigInteger('cancelled_by')->nullable());
                $this->add($table, 'cancellation_reason', fn () => $table->text('cancellation_reason')->nullable());
                $this->add($table, 'updated_at', fn () => $table->timestamp('updated_at')->nullable());
                $this->add($table, 'updated_by', fn () => $table->unsignedBigInteger('updated_by')->nullable());

                foreach ([
                    'vendor_name_snapshot',
                    'project_name_snapshot',
                    'client_name_snapshot',
                    'payment_terms_snapshot',
                    'bank_name_snapshot',
                    'bank_holder_name_snapshot',
                    'bank_account_snapshot',
                    'receipt_original_name',
                    'receipt_mime_type',
                    'receipt_sha256',
                    'receipt_state',
                ] as $column) {
                    $this->add($table, $column, fn () => $table->string($column, $column === 'receipt_sha256' ? 64 : 255)->nullable());
                }

                $this->add($table, 'award_value_snapshot', fn () => $table->decimal('award_value_snapshot', 14, 2)->nullable());
                $this->add($table, 'receipt_size', fn () => $table->unsignedBigInteger('receipt_size')->nullable());
            });

            Schema::table('vendor_payments', function (Blueprint $table): void {
                if (! $this->hasIndex('vendor_payments', 'vendor_payments_idempotency_unique')) {
                    $table->unique('idempotency_key', 'vendor_payments_idempotency_unique');
                }
                if (! $this->hasIndex('vendor_payments', 'vendor_payments_parent_idx')) {
                    $table->index('parent_payment_id', 'vendor_payments_parent_idx');
                }
                if (! $this->hasIndex('vendor_payments', 'vendor_payments_assignment_idx')) {
                    $table->index('project_vendor_assignment_id', 'vendor_payments_assignment_idx');
                }
            });
        }

        if (! Schema::hasTable('vendor_payment_transactions')) {
            Schema::create('vendor_payment_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_payment_id');
                $table->decimal('amount', 14, 2);
                $table->date('paid_date');
                $table->string('method', 100);
                $table->string('reference_number', 150);
                $table->string('proof_path')->nullable();
                $table->string('proof_original_name')->nullable();
                $table->string('proof_mime_type', 100)->nullable();
                $table->unsignedBigInteger('proof_size')->nullable();
                $table->string('proof_sha256', 64)->nullable();
                $table->string('bank_name_snapshot')->nullable();
                $table->string('bank_account_snapshot')->nullable();
                $table->text('remarks')->nullable();
                $table->unsignedBigInteger('created_by');
                $table->string('created_by_name_code', 80)->nullable();
                $table->string('idempotency_key', 120)->nullable()->unique();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();

                $table->index(['vendor_payment_id', 'reversed_at'], 'vendor_payment_transactions_active_idx');
            });
        }

        if (! Schema::hasTable('vendor_payment_events')) {
            Schema::create('vendor_payment_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vendor_payment_id');
                $table->string('event_type', 60);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->unsignedBigInteger('actor_staff_id')->nullable();
                $table->string('actor_name_code', 80)->nullable();
                $table->text('remarks')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['vendor_payment_id', 'created_at'], 'vendor_payment_events_payment_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_events');
        Schema::dropIfExists('vendor_payment_transactions');

        if (! Schema::hasTable('vendor_payments')) {
            return;
        }

        Schema::table('vendor_payments', function (Blueprint $table): void {
            if ($this->hasIndex('vendor_payments', 'vendor_payments_idempotency_unique')) {
                $table->dropUnique('vendor_payments_idempotency_unique');
            }
            foreach (['vendor_payments_parent_idx', 'vendor_payments_assignment_idx'] as $index) {
                if ($this->hasIndex('vendor_payments', $index)) {
                    $table->dropIndex($index);
                }
            }

            foreach ([
                'version', 'idempotency_key', 'project_vendor_assignment_id', 'parent_payment_id',
                'revision_number', 'superseded_by_payment_id', 'superseded_at', 'cancelled_at',
                'cancelled_by', 'cancellation_reason', 'updated_at', 'updated_by',
                'vendor_name_snapshot', 'project_name_snapshot', 'client_name_snapshot',
                'payment_terms_snapshot', 'award_value_snapshot', 'bank_name_snapshot',
                'bank_holder_name_snapshot', 'bank_account_snapshot', 'receipt_original_name',
                'receipt_mime_type', 'receipt_size', 'receipt_sha256', 'receipt_state',
            ] as $column) {
                if (Schema::hasColumn('vendor_payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function add(Blueprint $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn('vendor_payments', $column)) {
            $definition();
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $definition): bool => ($definition['name'] ?? null) === $index,
        );
    }
};
