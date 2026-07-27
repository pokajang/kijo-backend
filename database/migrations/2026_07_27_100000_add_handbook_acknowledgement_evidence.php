<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('hr_handbook_sign')
            ->select(['staff_id', 'handbook_version_id'])
            ->whereNotNull('handbook_version_id')
            ->groupBy('staff_id', 'handbook_version_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Duplicate staff/version handbook acknowledgements must be reviewed before adding the unique evidence constraint.',
            );
        }

        Schema::table('hr_handbook_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_handbook_versions', 'content_sha256')) {
                $table->char('content_sha256', 64)->nullable()->after('content_json');
            }
            if (! Schema::hasColumn('hr_handbook_versions', 'acknowledgement_schema_version')) {
                $table->unsignedSmallInteger('acknowledgement_schema_version')->nullable()->after('content_sha256');
            }
            if (! Schema::hasColumn('hr_handbook_versions', 'acknowledgement_sha256')) {
                $table->char('acknowledgement_sha256', 64)->nullable()->after('acknowledgement_schema_version');
            }
        });

        Schema::table('hr_handbook_sign', function (Blueprint $table) {
            $columns = [
                'submission_uuid' => fn () => $table->uuid('submission_uuid')->nullable(),
                'evidence_schema_version' => fn () => $table->unsignedSmallInteger('evidence_schema_version')->nullable(),
                'employee_code_snapshot' => fn () => $table->string('employee_code_snapshot', 50)->nullable(),
                'designation_snapshot' => fn () => $table->string('designation_snapshot')->nullable(),
                'department_snapshot' => fn () => $table->string('department_snapshot')->nullable(),
                'identity_number_encrypted' => fn () => $table->text('identity_number_encrypted')->nullable(),
                'signature_method' => fn () => $table->string('signature_method', 50)->nullable(),
                'signature_snapshot_path' => fn () => $table->string('signature_snapshot_path', 500)->nullable(),
                'signature_sha256' => fn () => $table->char('signature_sha256', 64)->nullable(),
                'handbook_content_sha256' => fn () => $table->char('handbook_content_sha256', 64)->nullable(),
                'acknowledgement_sha256' => fn () => $table->char('acknowledgement_sha256', 64)->nullable(),
                'evidence_payload_json' => fn () => $table->longText('evidence_payload_json')->nullable(),
                'signed_payload_sha256' => fn () => $table->char('signed_payload_sha256', 64)->nullable(),
                'evidence_hmac' => fn () => $table->char('evidence_hmac', 64)->nullable(),
                'evidence_key_id' => fn () => $table->string('evidence_key_id', 50)->nullable(),
            ];

            foreach ($columns as $name => $addColumn) {
                if (! Schema::hasColumn('hr_handbook_sign', $name)) {
                    $addColumn();
                }
            }
        });

        if (! Schema::hasTable('hr_handbook_sign_declarations')) {
            Schema::create('hr_handbook_sign_declarations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('handbook_sign_id');
                $table->string('declaration_id', 80);
                $table->string('declaration_title_snapshot');
                $table->longText('declaration_text_snapshot');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamp('accepted_at');
                $table->timestamps();

                $table->unique(
                    ['handbook_sign_id', 'declaration_id'],
                    'uq_handbook_sign_declaration',
                );
                $table->index('handbook_sign_id', 'idx_handbook_sign_declaration_sign');
                $table->index('declaration_id', 'idx_handbook_sign_declaration_key');
            });
        }

        Schema::table('hr_handbook_sign', function (Blueprint $table) {
            if (! Schema::hasIndex('hr_handbook_sign', 'uq_handbook_sign_submission_uuid')) {
                $table->unique(['submission_uuid'], 'uq_handbook_sign_submission_uuid');
            }
            if (! Schema::hasIndex('hr_handbook_sign', 'uq_handbook_sign_staff_version')) {
                $table->unique(
                    ['staff_id', 'handbook_version_id'],
                    'uq_handbook_sign_staff_version',
                );
            }
        });

        DB::table('hr_handbook_versions')
            ->whereNull('content_sha256')
            ->orderBy('id')
            ->eachById(function (object $version): void {
                DB::table('hr_handbook_versions')
                    ->where('id', $version->id)
                    ->update(['content_sha256' => hash('sha256', (string) $version->content_json)]);
            }, 100, 'id', 'id');
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_handbook_sign_declarations')
            && DB::table('hr_handbook_sign_declarations')->exists()) {
            throw new RuntimeException(
                'Cannot roll back handbook evidence schema after v2 declarations have been recorded.',
            );
        }

        Schema::dropIfExists('hr_handbook_sign_declarations');

        Schema::table('hr_handbook_sign', function (Blueprint $table) {
            if (Schema::hasIndex('hr_handbook_sign', 'uq_handbook_sign_submission_uuid')) {
                $table->dropUnique('uq_handbook_sign_submission_uuid');
            }
            if (Schema::hasIndex('hr_handbook_sign', 'uq_handbook_sign_staff_version')) {
                $table->dropUnique('uq_handbook_sign_staff_version');
            }
            $table->dropColumn([
                'submission_uuid',
                'evidence_schema_version',
                'employee_code_snapshot',
                'designation_snapshot',
                'department_snapshot',
                'identity_number_encrypted',
                'signature_method',
                'signature_snapshot_path',
                'signature_sha256',
                'handbook_content_sha256',
                'acknowledgement_sha256',
                'evidence_payload_json',
                'signed_payload_sha256',
                'evidence_hmac',
                'evidence_key_id',
            ]);
        });

        Schema::table('hr_handbook_versions', function (Blueprint $table) {
            $table->dropColumn([
                'content_sha256',
                'acknowledgement_schema_version',
                'acknowledgement_sha256',
            ]);
        });
    }
};
