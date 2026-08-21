<?php

namespace App\Services\Projects;

use App\Services\AuditLogService;
use App\Services\Word\CommercialWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use App\Support\PdfText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProjectLoaWordService extends WordRenderer
{
    public function __construct(
        private AuditLogService $auditLog,
        private CommercialWordDocumentBuilder $builder,
    ) {}

    public function generate(Request $request): mixed
    {
        $assignmentId = (int) $request->query('assignment_id', 0);
        $projectId = (int) $request->query('project_id', 0);
        $vendorId = (int) $request->query('vendor_id', 0);
        if ($assignmentId <= 0 && ($projectId <= 0 || $vendorId <= 0)) {
            return response()->json(['status' => 'error', 'message' => 'Missing assignment_id or project_id/vendor_id.'], 400);
        }

        $query = DB::table('project_vendors as pv')
            ->join('vendor_main_details as v', 'v.vendor_id', '=', 'pv.vendor_id')
            ->select([
                'pv.id as assignment_id', 'pv.project_id', 'pv.vendor_id', 'pv.award_value', 'pv.position',
                'pv.remarks', 'pv.services_description', 'pv.venue_details', 'pv.fee_breakdown',
                'pv.payment_terms', 'pv.loa_ref_no', 'v.vendor_name', 'v.contact_person_name',
                'v.mobile_number', 'v.email', 'v.address', 'v.city', 'v.state', 'v.zip',
            ])
            ->orderByDesc('pv.award_date')
            ->orderByDesc('pv.id')
            ->limit(1);

        if ($assignmentId > 0) {
            $query->where('pv.id', $assignmentId);
            if ($projectId > 0) $query->where('pv.project_id', $projectId);
            if ($vendorId > 0) $query->where('pv.vendor_id', $vendorId);
        } else {
            $query->where('pv.project_id', $projectId)->where('pv.vendor_id', $vendorId);
        }

        $loa = $query->first();
        if (! $loa) {
            return response()->json(['status' => 'error', 'message' => 'No matching vendor or project found.'], 404);
        }

        $data = $this->documentData($loa);
        $this->auditLog->log($request, "Generated LOA Word document for assignment ID #{$loa->assignment_id} (vendor ID #{$loa->vendor_id}) under project ID #{$loa->project_id}");

        return $this->download($this->builder->build($data, $request), $data['reference'].'_'.$loa->vendor_name.'.docx');
    }

    private function documentData(object $loa): array
    {
        $address = array_values(array_filter([
            (string) ($loa->vendor_name ?? ''),
            ...preg_split('/\R/u', trim((string) ($loa->address ?? ''))),
            trim(implode(', ', array_filter([(string) ($loa->city ?? ''), (string) ($loa->state ?? ''), (string) ($loa->zip ?? '')]))),
            $this->contactLine($loa),
        ], static fn (string $line): bool => $line !== ''));
        $value = static fn (mixed $input): string => str_replace(["\r\n", "\r"], "\n", trim((string) $input));

        return [
            'kind' => 'letter-of-award',
            'documentType' => 'LETTER OF AWARD',
            'reference' => (string) ($loa->loa_ref_no ?: 'LOA-UNKNOWN'),
            'date' => now()->format('d M Y'),
            'recipient' => $address,
            'contactName' => trim((string) ($loa->contact_person_name ?? 'Vendor')) ?: 'Vendor',
            'awardDetails' => [
                ['label' => 'Vendor Name', 'value' => (string) ($loa->vendor_name ?? '-')],
                ['label' => 'Position', 'value' => $value($loa->position) ?: '-'],
                ...$this->chunkedAwardDetail('Service Description', $value($loa->services_description)),
                ...$this->chunkedAwardDetail('Venue', $value($loa->venue_details)),
                ...$this->chunkedAwardDetail('Fee Breakdown', $value($loa->fee_breakdown)),
                ['label' => 'Award Amount', 'value' => 'RM '.number_format((float) ($loa->award_value ?? 0), 2), 'bold' => true],
                ...$this->chunkedAwardDetail('Payment Terms', $value($loa->payment_terms)),
                ...$this->chunkedAwardDetail('Remarks', $value($loa->remarks)),
            ],
            'termSections' => $this->termSections(),
        ];
    }

    private function contactLine(object $loa): string
    {
        $parts = [];
        if (trim((string) ($loa->email ?? '')) !== '') $parts[] = 'Email: '.trim((string) $loa->email);
        if (trim((string) ($loa->mobile_number ?? '')) !== '') $parts[] = 'Phone: '.trim((string) $loa->mobile_number);

        return implode('   ', $parts);
    }

    /** @return list<array{label: string, value: string}> */
    private function chunkedAwardDetail(string $label, string $value): array
    {
        $chunks = PdfText::chunks($value) ?: ['-'];

        return array_map(
            fn (string $chunk, int $index): array => ['label' => $index === 0 ? $label : '', 'value' => $chunk],
            $chunks,
            array_keys($chunks),
        );
    }

    private function termSections(): array
    {
        return [
            ['heading' => 'A. Compliance Commitment', 'paragraphs' => ['AMIOSH Resources Sdn. Bhd. is ISO 45001:2018 certified and fully compliant with Malaysian Occupational Health and Safety laws. Upon contract award, you must maintain this standard to protect AMIOSH, its clients, employees, and any affected third parties. You will assume full liability for any litigation arising from your work.']],
            ['heading' => 'B. Non-Compete and Brand Representation', 'paragraphs' => ["You must represent AMIOSH Resources Sdn. Bhd. exclusively in all communications and services. You may not act on the client's behalf, solicit future work, or promote your own services without AMIOSH's prior written consent.", 'You are forbidden from displaying any personal or third-party branding, including logos, uniforms, business cards, or identification, during service delivery. Only AMIOSH branding is allowed; any breach is a material violation and may lead to immediate termination and legal action.']],
            ['heading' => 'C. E-Invoice Compliance', 'paragraphs' => ['Upon contract award, you must promptly provide all supporting documentation, such as invoices and proof of service, for tax reporting and regulatory compliance. This cooperation ensures AMIOSH meets its legal obligations and maintains our professional relationship.']],
            ['heading' => 'D. General Commitments', 'paragraphs' => ['As an appointed vendor of AMIOSH Resources Sdn. Bhd., you hereby acknowledge and agree to the following terms and conditions which govern the conduct, responsibilities, and expectations throughout the duration of this engagement:'], 'items' => [
                'You shall comply with all Client site requirements, including the use of necessary personal protective equipment (PPE) such as safety shoes and safety helmets.',
                'You shall provide the services with due diligence, skill, and care, and in accordance with professional standards and industry best practices.',
                'You shall keep AMIOSH informed of the service progress and consult on matters requiring clarification. AMIOSH reserves the right to request variations, additions, or omissions to the scope of services as necessary to fulfill project or client requirements.',
                'Your engagement under this agreement is as an independent contractor. You are not authorized to act on behalf of, represent, or bind AMIOSH in any legal or financial capacity unless explicitly authorized in writing.',
                'You shall not publish, release, or communicate any statements, articles, reports, or commentary related to AMIOSH, its clients, or its operations to external parties or media without prior written approval.',
                'You must maintain strict confidentiality regarding all proprietary, financial, technical, or strategic information obtained during or after the duration of this engagement, including but not limited to project documents, pricing structures, methodologies, and internal systems.',
                'All information, data, or documentation created or shared in the course of this engagement remains the sole property of AMIOSH, unless otherwise stated in writing.',
                'Any form of misconduct including but not limited to dishonesty, insubordination, negligence, unauthorized absences, breach of confidentiality, or failure to perform may result in immediate termination of this engagement without prior notice, and AMIOSH reserves the right to seek legal redress or damages as appropriate.',
                'You shall not solicit, accept, or offer any gifts, commissions, or incentives that may influence or appear to influence business decisions or create a conflict of interest. Any such incident must be promptly reported to AMIOSH Management.',
                'You are responsible for the safekeeping and timely return of any AMIOSH property, equipment, documents, or resources provided to you for the performance of this engagement, in good working condition.',
                'All services and deliverables must comply with applicable local laws, client requirements, and any other standards or frameworks specified by AMIOSH.',
                'AMIOSH reserves the right to terminate this agreement at any time for breach of these terms or if, in its sole discretion, the vendor\'s continued engagement is not in the best interest of the company or its stakeholders.',
                'This Letter of Award shall be governed and construed in accordance with the laws of Malaysia. Any disputes arising shall be subject to the exclusive jurisdiction of the courts of Malaysia.',
            ]],
        ];
    }
}
