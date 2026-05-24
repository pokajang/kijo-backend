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
        if (! Schema::hasTable('legal_compliance_assessments')) {
            return;
        }

        DB::table('legal_compliance_assessments')
            ->whereNotNull('template_snapshot')
            ->orderBy('id')
            ->chunk(100, function ($assessments) {
                foreach ($assessments as $assessment) {
                    $snapshot = json_decode((string) $assessment->template_snapshot, true);
                    if (! is_array($snapshot) || ($snapshot['assessment_tier'] ?? 'free') !== 'paid') {
                        continue;
                    }

                    $snapshot['report_title'] = self::PAID_REPORT_TITLE;
                    $snapshot['disclaimer_text'] = self::PAID_DISCLAIMER;

                    DB::table('legal_compliance_assessments')
                        ->where('id', $assessment->id)
                        ->update([
                            'template_snapshot' => json_encode($snapshot),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // This migration standardizes paid report wording and is intentionally not reversible.
    }
};
