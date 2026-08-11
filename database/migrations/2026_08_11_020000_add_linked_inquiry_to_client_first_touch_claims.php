<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_first_touch_claims', function (Blueprint $table): void {
            $table->unsignedBigInteger('linked_inquiry_id')->nullable()->after('employment_departure_type');
            $table->index('linked_inquiry_id', 'client_first_touch_linked_inquiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_first_touch_claims', function (Blueprint $table): void {
            $table->dropIndex('client_first_touch_linked_inquiry_idx');
            $table->dropColumn('linked_inquiry_id');
        });
    }
};
