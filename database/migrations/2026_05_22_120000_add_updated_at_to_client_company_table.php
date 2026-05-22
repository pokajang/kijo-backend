<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_company') || Schema::hasColumn('client_company', 'updated_at')) {
            return;
        }

        Schema::table('client_company', function (Blueprint $table): void {
            if (Schema::hasColumn('client_company', 'created_at')) {
                $table->dateTime('updated_at')->nullable()->after('created_at');
                return;
            }

            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('client_company') && Schema::hasColumn('client_company', 'updated_at')) {
            Schema::table('client_company', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
