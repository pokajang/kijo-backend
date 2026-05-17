<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('quotes_training', 'proposed_end_date')) {
            Schema::table('quotes_training', function (Blueprint $table): void {
                $table->date('proposed_end_date')->nullable()->after('proposed_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes_training', 'proposed_end_date')) {
            Schema::table('quotes_training', function (Blueprint $table): void {
                $table->dropColumn('proposed_end_date');
            });
        }
    }
};
