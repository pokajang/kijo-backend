<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (Schema::hasColumn('manual_debtors', 'pic_name')) {
                $table->text('pic_name')->nullable()->change();
            }
            if (Schema::hasColumn('manual_debtors', 'pic_phone')) {
                $table->text('pic_phone')->nullable()->change();
            }
            if (Schema::hasColumn('manual_debtors', 'pic_email')) {
                $table->text('pic_email')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('manual_debtors', function (Blueprint $table): void {
            if (Schema::hasColumn('manual_debtors', 'pic_name')) {
                $table->string('pic_name', 191)->nullable()->change();
            }
            if (Schema::hasColumn('manual_debtors', 'pic_phone')) {
                $table->string('pic_phone', 80)->nullable()->change();
            }
            if (Schema::hasColumn('manual_debtors', 'pic_email')) {
                $table->string('pic_email', 191)->nullable()->change();
            }
        });
    }
};
