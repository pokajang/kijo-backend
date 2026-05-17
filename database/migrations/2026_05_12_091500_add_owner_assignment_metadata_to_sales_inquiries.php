<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_inquiries', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_assigned_by_id')->nullable()->after('owner_staff_name');
            $table->string('owner_assigned_by_code', 32)->nullable()->after('owner_assigned_by_id');
            $table->string('owner_assigned_by_name', 191)->nullable()->after('owner_assigned_by_code');
            $table->timestamp('owner_assigned_at')->nullable()->after('owner_assigned_by_name');
        });

        DB::table('sales_inquiries as si')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'si.created_by')
            ->whereNotNull('si.owner_staff_id')
            ->update([
                'si.owner_assigned_by_id' => DB::raw('si.created_by'),
                'si.owner_assigned_by_code' => DB::raw('si.created_by_code'),
                'si.owner_assigned_by_name' => DB::raw('sg.full_name'),
                'si.owner_assigned_at' => DB::raw('si.created_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales_inquiries', function (Blueprint $table) {
            $table->dropColumn([
                'owner_assigned_by_id',
                'owner_assigned_by_code',
                'owner_assigned_by_name',
                'owner_assigned_at',
            ]);
        });
    }
};
