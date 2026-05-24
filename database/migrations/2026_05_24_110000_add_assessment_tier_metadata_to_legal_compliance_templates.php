<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FREE_DISCLAIMER = 'This free assessment report is provided as a preliminary compliance review based on the information available during the assessment. It does not constitute legal advice or a full statutory audit. Further verification may be required before relying on this report for regulatory, contractual, or enforcement purposes.';

    public function up(): void
    {
        if (! Schema::hasTable('legal_compliance_templates')) {
            return;
        }

        Schema::table('legal_compliance_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('legal_compliance_templates', 'assessment_tier')) {
                $table->string('assessment_tier', 20)->default('free')->after('description');
            }

            if (! Schema::hasColumn('legal_compliance_templates', 'report_title')) {
                $table->string('report_title')->nullable()->after('assessment_tier');
            }

            if (! Schema::hasColumn('legal_compliance_templates', 'disclaimer_text')) {
                $table->text('disclaimer_text')->nullable()->after('report_title');
            }
        });

        DB::table('legal_compliance_templates')
            ->whereNull('assessment_tier')
            ->orWhere('assessment_tier', '')
            ->update(['assessment_tier' => 'free']);

        DB::table('legal_compliance_templates')
            ->orderBy('id')
            ->get()
            ->each(function ($template) {
                $reportTitle = trim((string) ($template->report_title ?? ''));
                if ($reportTitle === '') {
                    $reportTitle = trim((string) $template->name) === 'Free Legal Compliance Assessment'
                        ? 'Free Legal Compliance Assessment Report'
                        : trim((string) $template->name).' Report';
                }

                $disclaimer = trim((string) ($template->disclaimer_text ?? '')) ?: self::FREE_DISCLAIMER;
                DB::table('legal_compliance_templates')
                    ->where('id', $template->id)
                    ->update([
                        'assessment_tier' => in_array($template->assessment_tier ?? 'free', ['free', 'paid'], true)
                            ? $template->assessment_tier
                            : 'free',
                        'report_title' => $reportTitle,
                        'disclaimer_text' => $disclaimer,
                    ]);

                if (! Schema::hasTable('legal_compliance_template_versions')) {
                    return;
                }

                DB::table('legal_compliance_template_versions')
                    ->where('template_id', $template->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function ($version) use ($template, $reportTitle, $disclaimer) {
                        $content = json_decode((string) $version->content, true);
                        if (! is_array($content)) {
                            $content = [];
                        }

                        $content['assessment_tier'] = $content['assessment_tier'] ?? 'free';
                        $content['report_title'] = $content['report_title'] ?? $reportTitle;
                        $content['disclaimer_text'] = $content['disclaimer_text'] ?? $disclaimer;

                        DB::table('legal_compliance_template_versions')
                            ->where('id', $version->id)
                            ->update(['content' => json_encode($content)]);
                    });
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('legal_compliance_templates')) {
            return;
        }

        Schema::table('legal_compliance_templates', function (Blueprint $table) {
            foreach (['disclaimer_text', 'report_title', 'assessment_tier'] as $column) {
                if (Schema::hasColumn('legal_compliance_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
