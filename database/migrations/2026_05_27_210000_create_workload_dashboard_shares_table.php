<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workload_dashboard_shares')) {
            return;
        }

        Schema::create('workload_dashboard_shares', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('created_by_staff_id')->nullable()->index();
            $table->string('created_by_code')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->longText('payload_json');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_dashboard_shares');
    }
};
