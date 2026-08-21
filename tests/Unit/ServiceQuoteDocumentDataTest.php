<?php

namespace Tests\Unit;

use App\Services\QuoteRecords\ServiceQuoteDocumentData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommercialCycleQuoteSchemas;
use Tests\Support\IhCommercialCycleDatabase;
use Tests\TestCase;

class ServiceQuoteDocumentDataTest extends TestCase
{
    public function test_training_manpower_and_special_records_map_to_word_data(): void
    {
        CommercialCycleQuoteSchemas::replace('training');
        DB::table('quotes_training')->insert([...$this->common('QTR26-1'), 'training_title' => 'Working at Height', 'training_type' => 'In-house', 'venue' => 'Kajang', 'pax' => 12, 'unit_price' => 2000, 'training_total' => 2000, 'subtotal' => 2000, 'grand_total' => 2000]);
        $training = app(ServiceQuoteDocumentData::class)->find('training', 1);
        self::assertSame('QTR26-1', $training['quoteRefNo']);
        self::assertStringContainsString('Working at Height', $training['details'][0]['value']);

        CommercialCycleQuoteSchemas::replace('manpower');
        $this->createStaffTable();
        DB::table('staff_general')->insert(['staff_id' => 51, 'position' => 'Consultant', 'department' => 'Sales']);
        DB::table('quotes_manpower')->insert([...$this->common('QMP26-1'), 'created_by_id' => 51, 'service_title' => 'Safety Supervisor', 'service_code' => 'SSS', 'billing_unit' => 'month', 'duration_months' => 2, 'no_of_pax' => 1, 'unit_cost' => 4000, 'sub_total' => 8000, 'grand_total' => 8000]);
        $manpower = app(ServiceQuoteDocumentData::class)->find('manpower', 1);
        self::assertSame('Consultant (Sales)', $manpower['signOffTitle']);
        self::assertStringContainsString('Safety Supervisor', $manpower['details'][0]['value']);

        CommercialCycleQuoteSchemas::replace('special');
        DB::table('quotes_special')->insert([...$this->common('QSP26-1'), 'service_title' => 'Compliance Review', 'service_code' => 'CR', 'sub_total' => 1500, 'grand_total' => 1500]);
        DB::table('quotes_special_items')->insert(['quote_id' => 1, 'line_item_title' => 'Gap assessment', 'unit_price' => 1500, 'quantity' => 1, 'line_total' => 1500]);
        $special = app(ServiceQuoteDocumentData::class)->find('special', 1);
        self::assertSame('Gap assessment', $special['items'][0]['title']);
        self::assertStringContainsString('Compliance Review', $special['serviceSummary']);

        DB::table('quotes_special')->where('id', 1)->update(['attach_proposal' => true, 'sp_id' => 501]);
        DB::table('quotes_special_proposal_snapshots')->insert([
            'quote_id' => 1,
            'template_id' => 501,
            'proposal_mode' => 'upload',
            'service_title' => 'Uploaded Proposal',
            'attachments_json' => json_encode([['fileName' => 'scope.pdf', 'storedPath' => 'private/scope.pdf']]),
        ]);
        $specialWithUpload = app(ServiceQuoteDocumentData::class)->find('special', 1);
        self::assertSame('Attached Proposal', $specialWithUpload['proposalSections'][0]['title']);
        self::assertStringContainsString('scope.pdf', $specialWithUpload['proposalSections'][0]['content']);
    }

    public function test_industrial_hygiene_record_maps_to_word_data(): void
    {
        IhCommercialCycleDatabase::create();
        DB::table('quotes_ih')->insert([...$this->common('QIH26-1'), 'service_title' => 'Noise Risk Assessment', 'service_code' => 'NRA', 'site_address' => 'Kajang Plant', 'sample_counts' => 2, 'sample_unit' => 'area', 'num_work_units' => 1, 'unit_price' => 1000, 'sub_total' => 2000, 'grand_total' => 2000, 'pricing_rule_version' => 'ih_standard_v2']);
        $data = app(ServiceQuoteDocumentData::class)->find('ih', 1);
        self::assertSame('QIH26-1', $data['quoteRefNo']);
        self::assertStringContainsString('Noise Risk Assessment', $data['details'][0]['value']);
        self::assertStringContainsString('Kajang Plant', $data['details'][0]['value']);

        $this->createIhProposalTemplate();
        DB::table('quotes_ih')->where('id', 1)->update(['attach_proposal' => true, 'service_id' => 1]);
        DB::table('proposal_template_ih')->insert([
            'id' => 1,
            'service_title' => 'Noise Risk Proposal',
            'introduction' => '<p>Introduction content.</p>',
            'objectives' => '<p>Safety objective.</p>',
            'work_scope' => '<p>Work scope.</p>',
            'schedule' => '<p>Proposed schedule.</p>',
            'reference' => '<p>Reference documents.</p>',
            'other_fields' => '<p>Additional notes.</p>',
        ]);
        $dataWithProposal = app(ServiceQuoteDocumentData::class)->find('ih', 1);
        $proposalTitles = array_map(fn ($proposal): string => (string) ($proposal['title'] ?? ''), $dataWithProposal['proposalSections']);
        self::assertSame(['Introduction', 'Objectives', 'Work Scope', 'Schedule', 'References'], $proposalTitles);
        self::assertCount(1, $dataWithProposal['proposalAdditionalSections']);
        self::assertSame('Additional Information', $dataWithProposal['proposalAdditionalSections'][0]['title']);
        self::assertStringContainsString('Additional notes', $dataWithProposal['proposalAdditionalSections'][0]['content']);
    }

    private function common(string $reference): array
    {
        return ['id' => 1, 'quote_ref_no' => $reference, 'revision_no' => 0, 'client_name' => 'Client Sdn Bhd', 'client_address' => 'Kajang', 'pic_name' => 'Client PIC', 'pic_email' => 'client@example.test', 'pic_phone' => '0123', 'created_by_name' => 'Azam Azmi', 'created_by_code' => 'AZA', 'proposal_language' => 'en', 'attach_proposal' => false, 'created_at' => '2026-08-13 10:00:00', 'updated_at' => '2026-08-13 10:00:00'];
    }

    private function createStaffTable(): void
    {
        Schema::dropIfExists('staff_general');
        Schema::create('staff_general', function (Blueprint $table): void {
            $table->unsignedBigInteger('staff_id')->primary();
            $table->string('position')->nullable();
            $table->string('crm_position')->nullable();
            $table->string('department')->nullable();
        });
    }

    private function createIhProposalTemplate(): void
    {
        Schema::dropIfExists('proposal_template_ih');
        Schema::create('proposal_template_ih', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('service_title')->nullable();
            $table->text('introduction')->nullable();
            $table->text('objectives')->nullable();
            $table->text('work_scope')->nullable();
            $table->text('schedule')->nullable();
            $table->text('reference')->nullable();
            $table->text('other_fields')->nullable();
        });
    }
}
