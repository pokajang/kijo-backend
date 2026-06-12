<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('quotes_ih_items')) {
            return;
        }

        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('quote_id')->index();
            $table->string('item_description', 255);
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit', 50)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('quotes_ih')) {
            try {
                Schema::table('quotes_ih_items', function (Blueprint $table): void {
                    $table->foreign('quote_id', 'quotes_ih_items_quote_id_foreign')
                        ->references('id')
                        ->on('quotes_ih')
                        ->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes_ih_items');
    }
};
