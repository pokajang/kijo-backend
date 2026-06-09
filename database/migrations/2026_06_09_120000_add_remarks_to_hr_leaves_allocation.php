<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_leaves_allocation')) {
            return;
        }

        Schema::table('hr_leaves_allocation', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_leaves_allocation', 'remarks')) {
                $table->text('remarks')->nullable()->after('used_days');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_leaves_allocation')) {
            return;
        }

        Schema::table('hr_leaves_allocation', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_leaves_allocation', 'remarks')) {
                $table->dropColumn('remarks');
            }
        });
    }
};
