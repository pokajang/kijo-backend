<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IhStandardV1ClassificationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('quotes_ih', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('quote_ref_no');
            $table->decimal('sample_counts', 15, 2);
            $table->decimal('num_work_units', 15, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('travel_charge', 15, 2)->default(0);
            $table->unsignedTinyInteger('complexity_rating')->default(1);
            $table->decimal('complexity_markup', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('sst_percent', 15, 2)->default(0);
            $table->decimal('sst_amount', 15, 2)->default(0);
            $table->decimal('sub_total', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->string('pricing_rule_version', 40);
            $table->timestamps();
        });
        Schema::create('quotes_ih_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('quote_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
        });

        foreach (config('ih_standard_v1_repair.quotes') as $entry) {
            $isPrecisionVariance = (int) $entry['id'] === 68;
            DB::table('quotes_ih')->insert([
                'id' => $entry['id'],
                'quote_ref_no' => $entry['reference'],
                'sample_counts' => $isPrecisionVariance ? 120 : 1,
                'num_work_units' => 1,
                'unit_price' => $isPrecisionVariance ? 79.17 : $entry['grand_total'],
                'travel_charge' => 0,
                'complexity_rating' => 3,
                'complexity_markup' => 0,
                'discount' => $isPrecisionVariance ? 200 : 0,
                'sst_percent' => 0,
                'sst_amount' => 0,
                'sub_total' => $entry['sub_total'],
                'grand_total' => $entry['grand_total'],
                'pricing_rule_version' => 'ih_complexity_v1',
                'created_at' => '2026-02-26 11:42:59',
                'updated_at' => '2026-07-24 23:28:56',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('quotes_ih_items');
        Schema::dropIfExists('quotes_ih');

        parent::tearDown();
    }

    public function test_dry_run_is_read_only_and_commit_requires_matching_fingerprint(): void
    {
        $this->assertSame(2, Artisan::call('quotes:audit-ih-pricing-rules'));
        $this->assertStringContainsString('28 require action', Artisan::output());

        $this->assertSame(0, Artisan::call('quotes:classify-ih-standard-v1'));
        preg_match('/Confirmation fingerprint: ([a-f0-9]{64})/', Artisan::output(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $fingerprint = $matches[1];

        $this->assertSame(
            28,
            DB::table('quotes_ih')->where('pricing_rule_version', 'ih_complexity_v1')->count(),
        );

        $this->assertSame(1, Artisan::call('quotes:classify-ih-standard-v1', [
            '--commit' => true,
            '--confirm' => str_repeat('0', 64),
        ]));
        $this->assertSame(0, Artisan::call('quotes:classify-ih-standard-v1', [
            '--commit' => true,
            '--confirm' => $fingerprint,
        ]));

        $this->assertSame(
            28,
            DB::table('quotes_ih')->where('pricing_rule_version', 'ih_standard_v1')->count(),
        );
        $this->assertSame(9300.0, (float) DB::table('quotes_ih')->where('id', 68)->value('grand_total'));
        $this->assertSame(
            '2026-07-24 23:28:56',
            DB::table('quotes_ih')->where('id', 68)->value('updated_at'),
        );
        $this->assertSame(0, Artisan::call('quotes:audit-ih-pricing-rules'));
        $this->assertStringContainsString('0 require action', Artisan::output());

        $this->assertSame(0, Artisan::call('quotes:classify-ih-standard-v1', [
            '--rollback' => true,
        ]));
        preg_match('/Confirmation fingerprint: ([a-f0-9]{64})/', Artisan::output(), $rollback);
        $this->assertSame(0, Artisan::call('quotes:classify-ih-standard-v1', [
            '--rollback' => true,
            '--commit' => true,
            '--confirm' => $rollback[1],
        ]));
        $this->assertSame(
            28,
            DB::table('quotes_ih')->where('pricing_rule_version', 'ih_complexity_v1')->count(),
        );
    }

    public function test_fingerprint_change_stops_commit(): void
    {
        Artisan::call('quotes:classify-ih-standard-v1');
        preg_match('/Confirmation fingerprint: ([a-f0-9]{64})/', Artisan::output(), $matches);

        DB::table('quotes_ih')->where('id', 31)->update(['unit_price' => 7901]);

        $this->assertSame(1, Artisan::call('quotes:classify-ih-standard-v1', [
            '--commit' => true,
            '--confirm' => $matches[1],
        ]));
        $this->assertSame(
            28,
            DB::table('quotes_ih')->where('pricing_rule_version', 'ih_complexity_v1')->count(),
        );
    }

    public function test_audit_can_emit_machine_readable_release_evidence(): void
    {
        $this->assertSame(2, Artisan::call('quotes:audit-ih-pricing-rules', [
            '--format' => 'json',
        ]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(28, $report['summary']['audited']);
        $this->assertSame(28, $report['summary']['require_action']);
        $this->assertSame(28, $report['summary']['rule_counts']['ih_complexity_v1']);
        $this->assertCount(28, $report['quotes']);
        $this->assertSame('QIH26-0030INA', collect($report['quotes'])->firstWhere('id', 68)['reference']);
        $this->assertSame(-0.4, collect($report['quotes'])->firstWhere('id', 68)['difference']);
        $quote68 = collect($report['quotes'])->firstWhere('id', 68);
        $this->assertSame(9500.4, $quote68['components']['sub_total']['expected_gross']);
        $this->assertSame(9300.4, $quote68['components']['sub_total']['expected_for_rule']);
        $this->assertSame(
            'Stored totals match the documented intermediate-rule precision variance.',
            $quote68['reason'],
        );
    }

    public function test_audit_rejects_unknown_output_format(): void
    {
        $this->assertSame(1, Artisan::call('quotes:audit-ih-pricing-rules', [
            '--format' => 'xml',
        ]));
        $this->assertStringContainsString('must be table or json', Artisan::output());
    }

    public function test_audit_accepts_the_legacy_gross_subtotal_storage_convention(): void
    {
        DB::table('quotes_ih')->where('id', 31)->update([
            'sample_counts' => 1,
            'num_work_units' => 1,
            'unit_price' => 1000,
            'complexity_rating' => 1,
            'discount' => 300,
            'sst_percent' => 8,
            'sub_total' => 1000,
            'sst_amount' => 56,
            'grand_total' => 756,
        ]);

        Artisan::call('quotes:audit-ih-pricing-rules', ['--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $quote = collect($report['quotes'])->firstWhere('id', 31);

        $this->assertSame(27, $report['summary']['require_action']);
        $this->assertSame('assigned-rule-match-legacy-gross-subtotal', $quote['status']);
        $this->assertSame(
            'Legacy quote stores sub_total before discount; SST and grand total match.',
            $quote['reason'],
        );
        $this->assertEquals(0.0, $quote['components']['sub_total']['difference_from_gross']);
        $this->assertEquals(300.0, $quote['components']['sub_total']['difference_from_rule']);
    }

    public function test_audit_does_not_hide_a_real_legacy_component_mismatch(): void
    {
        DB::table('quotes_ih')->where('id', 31)->update([
            'sample_counts' => 1,
            'num_work_units' => 1,
            'unit_price' => 1000,
            'complexity_rating' => 1,
            'discount' => 300,
            'sst_percent' => 8,
            'sub_total' => 1000,
            'sst_amount' => 55,
            'grand_total' => 756,
        ]);

        Artisan::call('quotes:audit-ih-pricing-rules', ['--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $quote = collect($report['quotes'])->firstWhere('id', 31);

        $this->assertSame(28, $report['summary']['require_action']);
        $this->assertSame('unresolved', $quote['status']);
        $this->assertSame('Mismatch in: sub_total, sst_amount.', $quote['reason']);
        $this->assertEquals(-1.0, $quote['components']['sst_amount']['difference']);
    }

    public function test_gross_subtotal_exception_is_not_applied_to_standard_v1(): void
    {
        DB::table('quotes_ih')->where('id', 31)->update([
            'sample_counts' => 1,
            'num_work_units' => 1,
            'unit_price' => 1000,
            'complexity_rating' => 1,
            'discount' => 300,
            'sst_percent' => 8,
            'sub_total' => 1000,
            'sst_amount' => 56,
            'grand_total' => 756,
            'pricing_rule_version' => 'ih_standard_v1',
        ]);

        Artisan::call('quotes:audit-ih-pricing-rules', ['--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $quote = collect($report['quotes'])->firstWhere('id', 31);

        $this->assertSame('unresolved', $quote['status']);
        $this->assertSame('Mismatch in: sub_total.', $quote['reason']);
    }

    public function test_audit_rejects_additional_fee_rows_on_historical_rules(): void
    {
        DB::table('quotes_ih_items')->insert([
            'quote_id' => 31,
            'sort_order' => 0,
            'line_total' => 100,
        ]);

        Artisan::call('quotes:audit-ih-pricing-rules', ['--format' => 'json']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $quote = collect($report['quotes'])->firstWhere('id', 31);

        $this->assertSame('historical-fees-present', $quote['status']);
        $this->assertSame(
            'Additional-fee rows are not valid for this historical pricing rule.',
            $quote['reason'],
        );
    }
}
