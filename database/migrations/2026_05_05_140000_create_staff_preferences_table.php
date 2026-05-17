<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('preference_key', 191);
            $table->json('preference_value')->nullable();
            $table->timestamps();

            $table->unique(['staff_id', 'preference_key'], 'staff_preferences_staff_key_unique');
            $table->index('staff_id', 'staff_preferences_staff_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_preferences');
    }
};
