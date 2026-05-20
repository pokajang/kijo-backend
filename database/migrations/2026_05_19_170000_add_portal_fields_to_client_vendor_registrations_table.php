<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_vendor_registrations', function (Blueprint $table): void {
            if (!Schema::hasColumn('client_vendor_registrations', 'portal_url')) {
                $table->string('portal_url', 2048)->nullable()->after('certificate_size');
            }
            if (!Schema::hasColumn('client_vendor_registrations', 'portal_username')) {
                $table->string('portal_username')->nullable()->after('portal_url');
            }
            if (!Schema::hasColumn('client_vendor_registrations', 'portal_password')) {
                $table->string('portal_password')->nullable()->after('portal_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_vendor_registrations', function (Blueprint $table): void {
            if (Schema::hasColumn('client_vendor_registrations', 'portal_password')) {
                $table->dropColumn('portal_password');
            }
            if (Schema::hasColumn('client_vendor_registrations', 'portal_username')) {
                $table->dropColumn('portal_username');
            }
            if (Schema::hasColumn('client_vendor_registrations', 'portal_url')) {
                $table->dropColumn('portal_url');
            }
        });
    }
};
