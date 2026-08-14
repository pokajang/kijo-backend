<?php

namespace App\Services\QuoteRecords;

use App\Services\Quotes\Pricing\IhPricingCalculator;
use App\Support\PdfLabels;
use App\Support\PdfLegalTerms;
use App\Support\ProposalTitleFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ServiceQuoteDocumentData
{
    public function __construct(private IhPricingCalculator $ihPricing) {}

    public function find(string $service, int $quoteId): ?array
    {
        return match ($service) {
            'training' => $this->training($quoteId),
            'ih' => $this->ih($quoteId),
            'manpower' => $this->manpower($quoteId),
            'special' => $this->special($quoteId),
            default => null,
        };
    }

    private function training(int $id): ?array
    {
        $quote = DB::table('quotes_training')->where('id', $id)->first();
        if (! $quote) {
            return null;
        }
        $language = PdfLabels::normalize($quote->proposal_language ?? 'en');
        $sessionCount = (int) ($quote->session_count ?? 0);
        $duration = (int) ($quote->duration_per_session ?? 0);
        $unit = trim((string) ($quote->duration_unit ?? 'day(s)'));
        $perPax = $sessionCount <= 0 || $duration <= 0;
        $gross = (float) ($quote->training_total ?? 0) + (float) ($quote->meal_total ?? 0) + (float) ($quote->mobilization_cost ?? 0);
        $discount = (float) ($quote->discount_amount ?? 0);
        $hrd = (float) ($quote->hrd_amount ?? 0);
        $sst = (float) ($quote->sst_amount ?? 0);
        $date = $this->trainingDate($quote);
        $details = [
            $this->row($this->label($language, 'training_details', 'Training Details'), implode("\n", array_filter([
                $perPax ? 'Mode: '.($quote->training_type ?? '') : "Duration: {$duration} {$unit} x {$sessionCount} session(s) - Mode: ".($quote->training_type ?? ''),
                $this->label($language, 'course_title', 'Course Title').': '.($quote->training_title ?? '').' for '.(int) ($quote->pax ?? 0).' pax',
                $this->label($language, 'target_groups', 'Target groups').': '.($quote->target_groups ?? ''),
                $this->label($language, 'venue', 'Venue').': '.($quote->venue ?? ''),
                $this->label($language, 'date', 'Date').': '.$date,
                trim((string) ($quote->remarks ?? '')) !== '' ? $this->label($language, 'remarks', 'Remarks').': '.trim((string) $quote->remarks) : null,
            ]))),
            $this->row($this->label($language, 'unit_price_rm', 'Unit Price (RM)'), number_format((float) ($quote->unit_price ?? 0), 2).($perPax ? ' per pax' : " per {$unit}")),
            $this->row($this->label($language, 'travel_charge_rm', 'Travel Charge (RM)'), number_format((float) ($quote->travel_charge ?? 0), 2), (float) ($quote->travel_charge ?? 0) > 0),
            $this->row($this->label($language, 'meals_charge_rm', 'Meals Charge (RM)'), 'Yes @ RM '.number_format((float) ($quote->meal_price ?? 0), 2).' per pax', $this->truthy($quote->meals_provided ?? null) && (float) ($quote->meal_price ?? 0) > 0),
            $this->row($this->label($language, 'amount_rm', 'Amount (RM)'), number_format($gross, 2)),
            $this->row($this->label($language, 'discount_rm', 'Discount (RM)'), '- '.number_format($discount, 2), $discount > 0),
            $this->row($this->label($language, 'subtotal_rm', 'Subtotal (RM)'), number_format((float) ($quote->subtotal ?? $gross - $discount), 2), $discount > 0 && ($hrd > 0 || $sst > 0)),
            $this->row($this->rate($quote->hrd_charge ?? 0).'% HRD Charge (RM)', number_format($hrd, 2), $hrd > 0),
            $this->row($this->rate($quote->sst_rate ?? 0).'% '.$this->label($language, 'sst_charge_rm', 'SST Charge (RM)'), number_format($sst, 2), $sst > 0),
            $this->row($this->label($language, 'grand_total_rm', 'Grand Total (RM)'), number_format((float) ($quote->grand_total ?? 0), 2), true, true),
        ];
        [$proposalTitle, $proposalSections, $agenda] = $this->trainingProposal($quote);

        return $this->common($quote, $language, 'training', $details, [], [], $proposalTitle, $proposalSections, $agenda);
    }

    private function ih(int $id): ?array
    {
        $quote = DB::table('quotes_ih')->where('id', $id)->first();
        if (! $quote) {
            return null;
        }
        $language = PdfLabels::normalize($quote->proposal_language ?? 'en');
        $sampleCount = (float) ($quote->sample_counts ?? 0);
        $workUnits = (float) ($quote->num_work_units ?? 0);
        $discount = (float) ($quote->discount ?? 0);
        $travel = (float) ($quote->travel_charge ?? 0);
        $sst = (float) ($quote->sst_amount ?? 0);
        try {
            $rule = $this->ihPricing->normalizeRule($quote->pricing_rule_version ?? null);
        } catch (\InvalidArgumentException) {
            $rule = 'unknown';
        }
        $historical = $rule === 'unknown' || $this->ihPricing->isHistoricalRule($rule);
        $additional = $rule === IhPricingCalculator::STANDARD_RULE && Schema::hasTable('quotes_ih_items')
            ? DB::table('quotes_ih_items')->where('quote_id', $id)->orderBy('sort_order')->orderBy('id')->get()
            : collect();
        $additionalTotal = $additional->sum(fn ($item): float => (float) ($item->line_total ?? 0));
        $net = max(0, (float) ($quote->sub_total ?? 0));
        $gross = $historical ? $net + $discount : ($sampleCount * max(1, $workUnits) * (float) ($quote->unit_price ?? 0)) + $travel + $additionalTotal;
        $serviceTotal = $historical ? max(0, $gross - $travel) : $sampleCount * max(1, $workUnits) * (float) ($quote->unit_price ?? 0);
        $detailLines = [
            $this->label($language, 'service', 'Service').': '.($quote->service_title ?? '').' ('.($quote->service_code ?? '').')',
            $this->label($language, 'site_location', 'Site Location').': '.($quote->site_address ?? ''),
            'Samples: '.$this->number($sampleCount).' '.($quote->sample_unit ?? ''),
            'Work Units: '.($workUnits > 0 ? $this->number($workUnits) : 'N/A'),
            $this->label($language, 'remarks', 'Remarks').': '.(trim((string) ($quote->inquiry_remarks ?? '')) ?: '-'),
        ];
        $additionalText = $additional->map(fn ($item): string => trim((string) ($item->item_description ?? '')).' — '.number_format((float) ($item->line_total ?? 0), 2))->implode("\n");
        $details = [
            $this->row($this->label($language, 'service_details', 'Service Details'), implode("\n", $detailLines)),
            $this->row($this->label($language, 'amount_rm', 'Amount (RM)'), number_format($serviceTotal, 2)),
            $this->row('Additional Fees', $additionalText, $additional->isNotEmpty()),
            $this->row($this->label($language, 'travel_charge_rm', 'Travel Charge (RM)'), number_format($travel, 2), $travel > 0),
            $this->row($this->label($language, 'subtotal_rm', 'Subtotal (RM)'), number_format($gross, 2), true),
            $this->row($this->label($language, 'discount_rm', 'Discount (RM)'), '- '.number_format($discount, 2), $discount > 0),
            $this->row($this->rate($quote->sst_percent ?? 0).'% '.$this->label($language, 'sst_charge_rm', 'SST Charge (RM)'), number_format($sst, 2), $sst > 0),
            $this->row($this->label($language, 'grand_total_rm', 'Grand Total (RM)'), number_format((float) ($quote->grand_total ?? 0), 2), true, true),
        ];
        [$title, $sections] = $this->ihProposal($quote);

        return $this->common($quote, $language, 'ih', $details, [], [], $title, $sections);
    }

    private function manpower(int $id): ?array
    {
        $quote = DB::table('quotes_manpower as q')->leftJoin('staff_general as s', 's.staff_id', '=', 'q.created_by_id')->where('q.id', $id)
            ->select(['q.*', 's.position as staff_position', 's.crm_position', 's.department as staff_department'])->first();
        if (! $quote) {
            return null;
        }
        $language = PdfLabels::normalize($quote->proposal_language ?? 'en');
        $hourly = strtolower(trim((string) ($quote->billing_unit ?? ''))) === 'hour'
            || str_contains(strtolower(($quote->service_title ?? '').' '.($quote->service_code ?? '')), 'aesp')
            || abs((float) ($quote->unit_cost ?? 0) - 48.0) < .01;
        $duration = $hourly ? ((float) ($quote->duration_hours ?? 0) ?: (float) ($quote->duration_months ?? 0)) : (float) ($quote->duration_months ?? 0);
        $discount = (float) ($quote->discount ?? 0);
        $subtotal = (float) ($quote->sub_total ?? 0);
        $sst = (float) ($quote->sst_amount ?? 0);
        $details = [
            $this->row($this->label($language, 'service_details', 'Service Details'), implode("\n", array_filter([
                ($language === 'ms-MY' ? 'Jenis Pembekalan Tenaga Kerja' : 'Manpower Supply Type').': '.($quote->service_title ?? '').' ('.($quote->service_code ?? '').')',
                ($language === 'ms-MY' ? 'Skop Kerja' : 'Nature of Work').': '.($quote->nature_of_work ?? ''),
                $this->label($language, 'site_location', 'Site Location').': '.($quote->site_location ?? ''),
                'Duration & Pax: '.$this->number($duration).' '.($hourly ? 'hour(s)' : 'month(s)').' — '.(int) ($quote->no_of_pax ?? 0).' pax',
                trim((string) ($quote->inquiry_remarks ?? '')) !== '' ? $this->label($language, 'remarks', 'Remarks').': '.$quote->inquiry_remarks : null,
            ]))),
            $this->row($this->label($language, 'unit_price_rm', 'Unit Price (RM)'), 'RM '.number_format((float) ($quote->unit_cost ?? 0), 2).' '.($hourly ? 'per pax per hour' : 'per pax per month')),
            $this->row($this->label($language, 'amount_rm', 'Amount (RM)'), 'RM '.number_format($subtotal + $discount, 2)),
            $this->row($this->label($language, 'discount_rm', 'Discount (RM)'), '- RM '.number_format($discount, 2), $discount > 0),
            $this->row($this->label($language, 'subtotal_rm', 'Subtotal (RM)'), 'RM '.number_format($subtotal, 2), $discount > 0 && $sst > 0),
            $this->row($this->rate($quote->sst_percent ?? 0).'% '.$this->label($language, 'sst_charge_rm', 'SST Charge (RM)'), 'RM '.number_format($sst, 2), $sst > 0),
            $this->row($this->label($language, 'grand_total_rm', 'Grand Total (RM)'), 'RM '.number_format((float) ($quote->grand_total ?? 0), 2), true, true),
        ];
        [$title, $sections] = $this->manpowerProposal($quote);

        return $this->common($quote, $language, 'manpower', $details, [], [], $title, $sections);
    }

    private function special(int $id): ?array
    {
        $quote = DB::table('quotes_special as q')->leftJoin('staff_general as s', 's.staff_id', '=', 'q.created_by_id')->where('q.id', $id)
            ->select(['q.*', 's.position as staff_position', 's.crm_position', 's.department as staff_department'])->first();
        if (! $quote) {
            return null;
        }
        $language = PdfLabels::normalize($quote->proposal_language ?? 'en');
        $items = DB::table('quotes_special_items')->where('quote_id', $id)->orderBy('id')->get()->map(fn ($item): array => [
            'title' => (string) ($item->line_item_title ?? ''),
            'description' => (string) ($item->description ?? ''),
            'amount' => (float) ($item->line_total ?? 0),
        ])->all();
        $discount = (float) ($quote->discount ?? $quote->discount_amount ?? 0);
        $subtotal = (float) ($quote->sub_total ?? 0);
        $sst = (float) ($quote->sst_amount ?? 0);
        $totals = [
            $this->total($this->label($language, 'amount_rm', 'Amount (RM)'), 'RM '.number_format($subtotal + $discount, 2)),
            $this->total($this->label($language, 'discount_rm', 'Discount (RM)'), '- RM '.number_format($discount, 2), $discount > 0),
            $this->total($this->label($language, 'subtotal_rm', 'Subtotal (RM)'), 'RM '.number_format($subtotal, 2), $discount > 0 && $sst > 0),
            $this->total($this->rate($quote->sst_percent ?? 0).'% '.$this->label($language, 'sst_charge_rm', 'SST Charge (RM)'), 'RM '.number_format($sst, 2), $sst > 0),
            $this->total($this->label($language, 'grand_total_rm', 'Grand Total (RM)'), 'RM '.number_format((float) ($quote->grand_total ?? 0), 2), true, true),
        ];
        [$title, $sections] = $this->specialProposal($quote, $id);
        $data = $this->common($quote, $language, 'special', [], $items, $totals, $title, $sections);
        $data['serviceSummary'] = trim(($quote->service_title ?? '').' ('.($quote->service_code ?? '').')'.(trim((string) ($quote->general_remarks ?? '')) !== '' ? "\n".$this->label($language, 'remarks', 'Remarks').': '.$quote->general_remarks : ''));

        return $data;
    }

    private function common(object $quote, string $language, string $service, array $details, array $items, array $totals, string $proposalTitle = '', array $proposalSections = [], array $agenda = []): array
    {
        $created = (string) ($quote->created_at ?? '');
        $updated = (string) ($quote->updated_at ?? '');
        $signOff = trim((string) ($quote->crm_position ?? '')) ?: trim(((string) ($quote->staff_position ?? '')).' ('.((string) ($quote->staff_department ?? '')).')');
        if ($signOff === '' || $signOff === '()') {
            $staff = ! empty($quote->created_by_id) && Schema::hasTable('staff_general') ? DB::table('staff_general')->where('staff_id', $quote->created_by_id)->first() : null;
            $signOff = trim((string) ($staff->crm_position ?? '')) ?: trim(((string) ($staff->position ?? '')).' ('.((string) ($staff->department ?? '')).')');
        }
        $labels = $this->labels($language);
        $introKey = match ($service) {
            'training' => 'training_intro', 'ih' => 'ih_intro', 'manpower' => 'manpower_intro', default => 'special_intro'
        };
        $introFallback = match ($service) {
            'training' => 'Thank you for your interest in our training services. We are pleased to provide you with the following quotation.',
            'ih' => 'Thank you for your interest in our Industrial Hygiene services. We are pleased to provide you with the following quotation.',
            'manpower' => 'Thank you for your interest in our Manpower Supply services. We are pleased to provide you with the following quotation.',
            default => 'Thank you for your interest in our service. Please find below the quotation details.',
        };
        $termKeys = match ($service) {
            'training' => [['', 'training_quote']],
            'ih' => [[$this->label($language, 'general', 'General'), 'ih_general'], [$this->label($language, 'technical', 'Technical'), 'ih_technical']],
            'manpower' => [[$this->label($language, 'general', 'General'), 'manpower_general'], [$this->label($language, 'technical', 'Technical'), 'manpower_technical']],
            default => [[$this->label($language, 'general', 'General'), 'special_general'], [$this->label($language, 'technical', 'Technical'), 'special_technical']],
        };

        return [
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? ''), 'revisionNo' => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy' => $this->displayDate($created), 'createdDateIso' => $created !== '' ? substr($created, 0, 10) : '', 'updatedDateIso' => $updated !== '' ? substr($updated, 0, 10) : '',
            'picName' => (string) ($quote->pic_name ?? '-'), 'clientName' => (string) ($quote->client_name ?? '-'),
            'clientAddress' => $this->address($quote), 'picEmail' => (string) ($quote->pic_email ?? '-'), 'picPhone' => (string) ($quote->pic_phone ?? '-'),
            'preparedByName' => (string) ($quote->created_by_name ?? ''), 'signOffTitle' => $signOff ?: 'Staff',
            'language' => $language, 'labels' => $labels,
            'greeting' => ($language === 'ms-MY' ? 'Kepada ' : 'Dear ').$this->label($language, 'dear_valued_customer', 'Valued Customer').',',
            'intro' => $this->label($language, $introKey, $introFallback),
            'details' => $details, 'items' => $items, 'totals' => $totals,
            'reviewText' => $this->label($language, 'review_terms', 'Kindly review the terms and conditions outlined in the next page, and return a duly signed copy of this quotation as confirmation of your acceptance.'),
            'computerGeneratedText' => $this->label($language, 'computer_generated', '[This is a computer-generated document. No signature required.]'),
            'acceptanceText' => $this->label($language, 'acceptance_text', 'I/We hereby accept the terms and conditions stated in this quotation and confirm our intention to proceed.'),
            'terms' => array_map(fn ($entry): array => ['title' => $entry[0], 'items' => PdfLegalTerms::get($language, $entry[1])], $termKeys),
            'proposalTitle' => $proposalTitle, 'proposalSections' => $proposalSections, 'proposalAgenda' => $agenda,
            'serviceSummary' => '',
        ];
    }

    private function trainingProposal(object $quote): array
    {
        if (! $this->truthy($quote->attach_proposal ?? null) || (int) ($quote->proposal_id ?? 0) < 1) {
            return ['', [], []];
        }
        $p = DB::table('proposal_template_training_main')->where('id', $quote->proposal_id)->where('is_deleted', 0)->first();
        if (! $p) {
            return ['', [], []];
        }
        $map = ['HRDC Training Programme No.' => 'hrd_no', 'Introduction' => 'introduction', 'Objectives' => 'objectives', 'Modules' => 'modules', 'Training Requirements' => 'training_requirements', 'Additional Requirements' => 'additional_requirements', 'Training Materials' => 'training_materials', 'Lecture Medium' => 'lecture_medium', 'Theory Method' => 'method_theory_desc', 'Practical Method' => 'method_practical_desc', 'Duration' => 'duration'];
        $sections = $this->sections($p, $map);
        $agenda = [];
        foreach (DB::table('proposal_template_training_agenda')->where('template_id', $p->id)->orderBy('day')->orderBy('start_time')->get() as $row) {
            $day = max(1, (int) ($row->day ?? 1));
            $agenda[$day][] = ['time' => $this->time($row->start_time ?? null).' - '.$this->time($row->end_time ?? null), 'topic' => (string) ($row->topic ?? $row->activity ?? '')];
        }

        return [ProposalTitleFormatter::formatProposalTitle((string) ($p->training_title ?? ''), null, '', 'training-word'), $sections, $agenda];
    }

    private function ihProposal(object $quote): array
    {
        if (! $this->truthy($quote->attach_proposal ?? null) || (int) ($quote->service_id ?? 0) < 1) {
            return ['', []];
        }
        $p = DB::table('proposal_template_ih')->where('id', $quote->service_id)->first();
        if (! $p) {
            return ['', []];
        }

        return [ProposalTitleFormatter::formatProposalTitle((string) ($p->service_title ?? ''), 'Service Proposal', 'Service Proposal', 'ih-word'), $this->sections($p, ['Introduction' => 'introduction', 'Objectives' => 'objectives', 'Work Scope' => 'work_scope', 'Schedule' => 'schedule', 'References' => 'reference', 'Additional Information' => 'other_fields'])];
    }

    private function manpowerProposal(object $quote): array
    {
        if (! $this->truthy($quote->attach_proposal ?? null) || (int) ($quote->mp_id ?? 0) < 1) {
            return ['', []];
        }
        $p = DB::table('proposal_template_manpower')->where('id', $quote->mp_id)->first();
        if (! $p) {
            return ['', []];
        }

        return [ProposalTitleFormatter::formatProposalTitle((string) ($p->service_title ?? ''), 'Manpower Supply Service Proposal', 'Manpower Supply Service Proposal', 'manpower-word'), $this->sections($p, ['Introduction' => 'introduction', 'Service Deliverables' => 'service_deliverables', 'Supplied Manpower Deliverables' => 'supplied_manpower_deliverables', 'Additional Information' => 'custom_section'])];
    }

    private function specialProposal(object $quote, int $id): array
    {
        if (! $this->truthy($quote->attach_proposal ?? null) || (int) ($quote->sp_id ?? 0) < 1) {
            return ['', []];
        }
        $snapshot = Schema::hasTable('quotes_special_proposal_snapshots') ? DB::table('quotes_special_proposal_snapshots')->where('quote_id', $id)->first() : null;
        if ($snapshot && ($snapshot->proposal_mode ?? 'upload') === 'write') {
            return [ProposalTitleFormatter::formatProposalTitle((string) ($snapshot->service_title ?? ''), 'Service Proposal', 'Service Proposal', 'special-word-snapshot'), [['title' => 'Proposal', 'content' => (string) ($snapshot->proposal_content ?? '')]]];
        }
        if ($snapshot && ($snapshot->proposal_mode ?? 'upload') === 'upload') {
            return $this->uploadedSpecialProposal(
                (string) ($snapshot->service_title ?? ''),
                (string) ($snapshot->attachments_json ?? ''),
            );
        }
        $p = DB::table('proposal_template_special')->where('id', $quote->sp_id)->where('is_deleted', 0)->first();
        if ($p && ($p->proposal_mode ?? 'write') === 'write') {
            return [ProposalTitleFormatter::formatProposalTitle((string) ($p->service_title ?? ''), 'Service Proposal', 'Service Proposal', 'special-word'), [['title' => 'Proposal', 'content' => (string) ($p->proposal_content ?? $p->content ?? '')]]];
        }
        if ($p && ($p->proposal_mode ?? 'upload') === 'upload') {
            return $this->uploadedSpecialProposal((string) ($p->service_title ?? ''), '');
        }

        return ['', []];
    }

    private function uploadedSpecialProposal(string $title, string $attachmentsJson): array
    {
        $attachments = json_decode($attachmentsJson, true);
        $names = is_array($attachments) ? array_values(array_filter(array_map(
            fn ($attachment): string => is_array($attachment) ? trim((string) ($attachment['fileName'] ?? '')) : '',
            $attachments,
        ))) : [];
        $content = 'The original proposal was supplied as PDF attachment(s). PDF pages cannot be embedded as editable native Word content without reducing cross-platform compatibility.';
        if ($names !== []) {
            $content .= "\nIncluded source attachment(s):\n- ".implode("\n- ", $names);
        }

        return [
            ProposalTitleFormatter::formatProposalTitle($title, 'Service Proposal', 'Service Proposal', 'special-word-upload'),
            [['title' => 'Attached Proposal', 'content' => $content]],
        ];
    }

    private function sections(object $source, array $map): array
    {
        $out = [];
        foreach ($map as $title => $field) {
            $content = trim((string) ($source->{$field} ?? ''));
            if (trim(strip_tags(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== '') {
                $out[] = ['title' => $title, 'content' => $content];
            }
        }

        return $out;
    }

    private function labels(string $lang): array
    {
        $pairs = ['quoteNumber' => ['quote_number', 'Quote Number'], 'revDate' => ['rev_date', 'Rev. Date'], 'oriDate' => ['ori_date', 'Ori. Date'], 'date' => ['date', 'Date'], 'attentionTo' => ['attention_to', 'Attention To'], 'email' => ['email', 'Email'], 'phone' => ['phone', 'Phone'], 'preparedBy' => ['prepared_by', 'Prepared by'], 'customerAcceptance' => ['customer_acceptance', 'Customer Acceptance'], 'name' => ['name', 'Name'], 'position' => ['position', 'Position'], 'signature' => ['signature', 'Signature'], 'companyStamp' => ['company_stamp', 'Company Stamp'], 'terms' => ['terms_and_conditions', 'Terms and Conditions'], 'amount' => ['amount_rm', 'Amount (RM)'], 'lineItem' => ['line_item', 'Line Item'], 'service' => ['service', 'Service'], 'notes' => ['notes', 'Notes']];

        return array_map(fn ($pair): string => $this->label($lang, $pair[0], $pair[1]), $pairs);
    }

    private function label(string $lang, string $key, string $fallback): string
    {
        return PdfLabels::get($lang, $key, $fallback);
    }

    private function row(string $label, string $value, bool $show = true, bool $bold = false): array
    {
        return compact('label', 'value', 'show', 'bold');
    }

    private function total(string $label, string $value, bool $show = true, bool $bold = false): array
    {
        return compact('label', 'value', 'show', 'bold');
    }

    private function rate(mixed $v): string
    {
        $n = (float) $v;

        return (float) (int) $n === $n ? number_format($n, 0) : number_format($n, 2);
    }

    private function number(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function truthy(mixed $v): bool
    {
        return in_array(strtolower(trim((string) $v)), ['1', 'yes', 'true'], true);
    }

    private function displayDate(string $v): string
    {
        $time = $v !== '' ? strtotime($v) : false;

        return $time === false ? '' : date('d M Y', $time);
    }

    private function time(mixed $v): string
    {
        $time = $v ? strtotime((string) $v) : false;

        return $time === false ? '-' : date('g:i A', $time);
    }

    private function trainingDate(object $q): string
    {
        if ($this->truthy($q->to_be_confirmed ?? null) || empty($q->proposed_date) || $q->proposed_date === '0000-00-00') {
            return 'To be Confirmed — Confirmed Date: ______________________________';
        } $start = $this->displayDate((string) $q->proposed_date);
        $end = ! empty($q->proposed_end_date) && $q->proposed_end_date !== $q->proposed_date ? $this->displayDate((string) $q->proposed_end_date) : '';

        return $end !== '' ? "{$start} - {$end}" : $start;
    }

    private function address(object $q): string
    {
        return implode("\n", array_filter([trim((string) ($q->client_address ?? '')), trim(implode(' ', array_filter([(string) ($q->client_zip ?? ''), (string) ($q->client_city ?? ''), (string) ($q->client_state ?? '')])))]));
    }
}
