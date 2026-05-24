<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PAID_REPORT_TITLE = 'Occupational Safety and Health Legal Compliance Assessment Report';

    private const PAID_DISCLAIMER = 'This report presents the findings of a legal compliance assessment based on the scope, information, documents, and site observations available at the time of assessment. It reflects the assessor\'s professional opinion on the applicable requirements reviewed and does not constitute legal advice or a regulatory determination.';

    public function up(): void
    {
        if (! Schema::hasTable('legal_compliance_templates')) {
            return;
        }

        DB::table('legal_compliance_templates')
            ->where('assessment_tier', 'paid')
            ->update([
                'report_title' => self::PAID_REPORT_TITLE,
                'disclaimer_text' => self::PAID_DISCLAIMER,
                'updated_at' => now(),
            ]);

        DB::table('legal_compliance_templates')
            ->where('assessment_tier', 'paid')
            ->orderBy('id')
            ->chunk(100, function ($templates) {
                foreach ($templates as $template) {
                    $draftContent = $this->metadataContent($template->draft_content ?? null, $template->name ?? null);

                    DB::table('legal_compliance_templates')
                        ->where('id', $template->id)
                        ->update([
                            'draft_content' => json_encode($draftContent),
                        ]);

                    if (Schema::hasTable('legal_compliance_template_versions')) {
                        DB::table('legal_compliance_template_versions')
                            ->where('template_id', $template->id)
                            ->orderBy('id')
                            ->chunk(100, function ($versions) {
                                foreach ($versions as $version) {
                                    DB::table('legal_compliance_template_versions')
                                        ->where('id', $version->id)
                                        ->update([
                                            'content' => json_encode($this->metadataContent($version->content ?? null)),
                                        ]);
                                }
                            });
                    }
                }
            });
    }

    public function down(): void
    {
        // This migration normalizes current paid assessment wording; it is intentionally not reversible.
    }

    private function metadataContent(mixed $rawContent, ?string $templateName = null): array
    {
        $content = json_decode((string) $rawContent, true);
        $content = is_array($content) ? $content : [];

        return [
            ...$content,
            'title' => $content['title'] ?? ($templateName ?: 'Legal Compliance Assessment'),
            'assessment_tier' => 'paid',
            'report_title' => self::PAID_REPORT_TITLE,
            'disclaimer_text' => self::PAID_DISCLAIMER,
            'groups' => is_array($content['groups'] ?? null) ? $content['groups'] : [],
        ];
    }
};
