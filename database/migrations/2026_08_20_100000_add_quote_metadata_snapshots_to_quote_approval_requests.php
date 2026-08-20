<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            return;
        }

        if (! Schema::hasColumn('quote_approval_requests', 'quote_title')) {
            Schema::table('quote_approval_requests', function (Blueprint $table): void {
                $table->string('quote_title')->nullable()->after('quote_ref_no');
            });
        }
        if (! Schema::hasColumn('quote_approval_requests', 'quote_date')) {
            Schema::table('quote_approval_requests', function (Blueprint $table): void {
                $table->timestamp('quote_date')->nullable()->after('quote_title');
            });
        }
        if (! Schema::hasColumn('quote_approval_requests', 'client_name')) {
            Schema::table('quote_approval_requests', function (Blueprint $table): void {
                $table->string('client_name')->nullable()->after('quote_date');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('quote_approval_requests')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('quote_approval_requests', 'quote_title') ? 'quote_title' : null,
            Schema::hasColumn('quote_approval_requests', 'quote_date') ? 'quote_date' : null,
            Schema::hasColumn('quote_approval_requests', 'client_name') ? 'client_name' : null,
        ]));
        if ($columns !== []) {
            Schema::table('quote_approval_requests', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
