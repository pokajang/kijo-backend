<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $templateTables = [
        'proposal_template_training_main',
        'proposal_template_ih',
        'proposal_template_manpower',
        'proposal_template_special',
    ];

    private array $quoteTables = [
        'quotes_training',
        'quotes_ih',
        'quotes_manpower',
        'quotes_special',
    ];

    public function up(): void
    {
        foreach ($this->templateTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'proposal_language')) {
                    $table->string('proposal_language', 10)->default('en')->index();
                }
                if (!Schema::hasColumn($tableName, 'source_template_id')) {
                    $table->unsignedBigInteger('source_template_id')->nullable()->index();
                }
                if (!Schema::hasColumn($tableName, 'translation_provider')) {
                    $table->string('translation_provider', 50)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'translation_status')) {
                    $table->string('translation_status', 50)->nullable();
                }
                if (!Schema::hasColumn($tableName, 'translated_at')) {
                    $table->timestamp('translated_at')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'translation_notes')) {
                    $table->text('translation_notes')->nullable();
                }
            });

            DB::table($tableName)
                ->whereNull('proposal_language')
                ->orWhere('proposal_language', '')
                ->update(['proposal_language' => 'en']);
        }

        foreach ($this->quoteTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'proposal_language')) {
                    $table->string('proposal_language', 10)->default('en')->index();
                }
            });

            DB::table($tableName)
                ->whereNull('proposal_language')
                ->orWhere('proposal_language', '')
                ->update(['proposal_language' => 'en']);
        }

        $this->addLanguageColumn('projects_main', 'proposal_language');
        $this->addLanguageColumn('invoices', 'document_language');
        $this->addLanguageColumn('do_details', 'document_language');
    }

    public function down(): void
    {
        foreach ($this->templateTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach ([
                    'proposal_language',
                    'source_template_id',
                    'translation_provider',
                    'translation_status',
                    'translated_at',
                    'translation_notes',
                ] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        foreach ($this->quoteTables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'proposal_language')) {
                    $table->dropColumn('proposal_language');
                }
            });
        }

        $this->dropLanguageColumn('projects_main', 'proposal_language');
        $this->dropLanguageColumn('invoices', 'document_language');
        $this->dropLanguageColumn('do_details', 'document_language');
    }

    private function addLanguageColumn(string $tableName, string $column): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->string($column, 10)->default('en')->index();
        });

        DB::table($tableName)
            ->whereNull($column)
            ->orWhere($column, '')
            ->update([$column => 'en']);
    }

    private function dropLanguageColumn(string $tableName, string $column): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $column)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropColumn($column);
        });
    }
};
