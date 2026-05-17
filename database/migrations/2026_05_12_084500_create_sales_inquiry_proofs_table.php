<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_inquiry_proofs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_inquiry_id');
            $table->string('proof_path', 255);
            $table->string('original_name', 191)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sales_inquiry_id', 'sort_order'], 'sales_inquiry_proofs_inquiry_order_idx');
        });

        if (Schema::hasTable('sales_inquiries')) {
            $now = now();
            DB::table('sales_inquiries')
                ->whereNotNull('proof_path')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($now): void {
                    $proofs = [];

                    foreach ($rows as $row) {
                        $proofs[] = [
                            'sales_inquiry_id' => (int) $row->id,
                            'proof_path' => (string) $row->proof_path,
                            'original_name' => $row->proof_original_name ?: basename((string) $row->proof_path),
                            'mime_type' => $row->proof_mime_type ?: null,
                            'file_size' => null,
                            'sort_order' => 0,
                            'created_at' => $row->created_at ?: $now,
                            'updated_at' => $row->updated_at ?: $now,
                        ];
                    }

                    if (!empty($proofs)) {
                        DB::table('sales_inquiry_proofs')->insert($proofs);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_inquiry_proofs');
    }
};
