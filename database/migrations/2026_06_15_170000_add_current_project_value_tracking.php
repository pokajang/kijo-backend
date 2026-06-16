<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('projects_main') && ! Schema::hasColumn('projects_main', 'current_project_value')) {
            Schema::table('projects_main', function (Blueprint $table): void {
                $table->decimal('current_project_value', 15, 2)->nullable()->after('quote_value');
            });
        }

        if (! Schema::hasTable('project_value_revisions')) {
            Schema::create('project_value_revisions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('quote_id')->nullable();
                $table->string('quote_type', 50)->nullable();
                $table->string('source', 50);
                $table->decimal('old_value', 15, 2)->nullable();
                $table->decimal('new_value', 15, 2);
                $table->decimal('awarded_value', 15, 2)->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('changed_at')->nullable();
                $table->timestamps();

                $table->index(['project_id', 'changed_at']);
                $table->index(['quote_type', 'quote_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_value_revisions');

        if (Schema::hasTable('projects_main') && Schema::hasColumn('projects_main', 'current_project_value')) {
            Schema::table('projects_main', function (Blueprint $table): void {
                $table->dropColumn('current_project_value');
            });
        }
    }
};
