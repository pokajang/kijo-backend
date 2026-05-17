<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Letter of Award — {{ $refNo }}</title>
    <style>
        @page {
            margin: 10mm 20mm 10mm 20mm;
        }
        body {
            margin: 0;
            color: #111;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            line-height: 1.35;
            text-align: justify;
        }
        /* ── Header ── */
        .pdf-header { color: #696969; margin-bottom: 4mm; }
        .header-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .header-table td { vertical-align: top; padding: 0; }
        .header-left  { width: 68%; text-align: left; }
        .header-right { width: 32%; text-align: right; }
        .company-name    { font-size: 10pt; font-weight: 700; margin-bottom: 1.5mm; }
        .company-address { font-size: 10pt; line-height: 1.2; margin-bottom: 1.5mm; }
        .company-contact { font-size: 10pt; font-weight: 700; }
        .company-logo    { width: 42mm; height: auto; display: inline-block; margin-top: -1mm; }
        .document-type   { font-size: 10pt; font-weight: 700; margin-top: 2.2mm; letter-spacing: 0.3px; }
        .header-separator { margin-top: 1.3mm; border-bottom: 0.7px solid #696969; }
        /* ── Body text ── */
        p  { margin: 0 0 2mm 0; }
        strong { font-weight: 700; }
        /* ── Award table ── */
        .award-table { width: 100%; border-collapse: collapse; margin: 2mm 0 2.2mm 0; font-size: 10.5pt; }
        .award-table td { border: 0.5px solid #000; padding: 4px 5px; vertical-align: top; text-align: left; }
        .award-table td:first-child { width: 30%; font-weight: 700; }
        /* ── Signature table ── */
        .sig-table { width: 100%; border-collapse: collapse; margin-top: 2mm; font-size: 10pt; }
        .sig-table td { border: 0.5px solid #000; width: 50%; height: 30mm; vertical-align: top; padding: 4px; }
        /* ── T&C page ── */
        h3 { font-size: 11pt; margin: 0 0 3mm 0; }
        .tc-section { margin-bottom: 4mm; }
        .tc-section-title { font-size: 10pt; font-weight: 700; margin-bottom: 2mm; }
        ol { margin: 0 0 3mm 0; padding-left: 6mm; }
        ol li { margin-bottom: 1.5mm; font-size: 9.5pt; }
        .page-break { page-break-before: always; }
        .italic-note { font-size: 9pt; font-style: italic; color: #555; }
        .ack-title { font-weight: 700; font-size: 10.5pt; margin-bottom: 2mm; margin-top: 5mm; }
    </style>
</head>
<body>

{{-- ── Page 1: Letter of Award ── --}}

@include('pdf.partials.company-header', [
    'documentType' => 'LETTER OF AWARD',
    'logoDataUri' => $logoDataUri ?? null,
])

{{-- Ref and date --}}
<p>Our Ref: <strong>{{ $refNo }}</strong> &nbsp;&nbsp;&nbsp; Date: {{ $printDate }}</p>

{{-- Vendor address block --}}
<p>
    <strong>Attention To:</strong><br>
    {{ $data->vendor_name }}<br>
    {{ $data->address }},<br>
    {{ $data->city }}, {{ $data->state }} {{ $data->zip }}<br>
    Email: {{ $data->email }}
    &nbsp;&nbsp;&nbsp;
    Phone: {{ $data->mobile_number }}
</p>

<br>

<p>Dear <strong>{{ $data->contact_person_name }}</strong>,</p>

<p>
    We are pleased to inform you that <strong>AMIOSH RESOURCES SDN BHD</strong> hereby awards the contract
    for the following services under the terms outlined below:
</p>

{{-- Award details table --}}
<table class="award-table">
    <tr>
        <td>Vendor Name</td>
        <td>{{ $data->vendor_name }}</td>
    </tr>
    <tr>
        <td>Position</td>
        <td>{!! $data->position !!}</td>
    </tr>
    <tr>
        <td>Service Description</td>
        <td>{!! $services !!}</td>
    </tr>
    <tr>
        <td>Venue</td>
        <td>{!! $venue !!}</td>
    </tr>
    <tr>
        <td>Fee Breakdown</td>
        <td>{!! $breakdown !!}</td>
    </tr>
    <tr>
        <td>Award Amount</td>
        <td><strong>{{ $formattedAward }}</strong></td>
    </tr>
    <tr>
        <td>Payment Terms</td>
        <td>{!! $data->payment_terms !!}</td>
    </tr>
    <tr>
        <td>Remarks</td>
        <td>{!! $remarks !!}</td>
    </tr>
</table>

<p style="margin-top: 4mm;">
    Please review the terms and conditions on the following page and
    <strong>return us a signed copy</strong> of this contract.
</p>

<p style="margin-top: 4mm;">
    With best wishes,<br>
    <strong>Muhammad Amin Bin Rozak</strong><br>
    Managing Director<br>
    AMIOSH RESOURCES SDN BHD
</p>

<p class="italic-note">[This is a computer-generated document. No signature is required from us.]</p>

<p class="ack-title">Vendor Acknowledgement</p>

<p style="font-size: 10pt;">
    I hereby acknowledge and accept the terms and conditions set forth in this Letter of Award and shall
    deliver the services indicated with full responsibility and professionalism.
</p>

<table class="sig-table">
    <tr>
        <td style="width: 50%;">
            <br>
            Signature:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<br><br>
            Name:
        </td>
        <td style="width: 50%;">
            <br>
            NRIC Number:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<br><br>
            Date:
        </td>
    </tr>
</table>

{{-- ── Page 2: Terms and Conditions ── --}}
<div class="page-break"></div>

@include('pdf.partials.company-header', [
    'documentType' => 'LETTER OF AWARD',
    'logoDataUri' => $logoDataUri ?? null,
])

<h3>Terms and Conditions</h3>

<div class="tc-section">
    <div class="tc-section-title">A. Compliance Commitment</div>
    <p style="font-size: 9.5pt;">
        AMIOSH Resources Sdn. Bhd. is ISO 45001:2018 certified and fully compliant with Malaysian Occupational
        Health and Safety laws. Upon contract award, you must maintain this standard to protect AMIOSH, its
        clients, employees, and any affected third parties. You will assume full liability for any litigation
        arising from your work.
    </p>
</div>

<div class="tc-section">
    <div class="tc-section-title">B. Non-Compete and Brand Representation</div>
    <p style="font-size: 9.5pt;">
        You must represent AMIOSH Resources Sdn. Bhd. exclusively in all communications and services.
        You may not act on the client's behalf, solicit future work, or promote your own services without
        AMIOSH's prior written consent.
    </p>
    <p style="font-size: 9.5pt;">
        You are forbidden from displaying any personal or third-party branding—logos, uniforms, business
        cards, or identification—during service delivery. Only AMIOSH branding is allowed; any breach is
        a material violation and may lead to immediate termination and legal action.
    </p>
</div>

<div class="tc-section">
    <div class="tc-section-title">C. E-Invoice Compliance</div>
    <p style="font-size: 9.5pt;">
        Upon contract award, you must promptly provide all supporting documentation—such as invoices and
        proof of service—for tax reporting and regulatory compliance. This cooperation ensures AMIOSH meets
        its legal obligations and maintains our professional relationship.
    </p>
</div>

<div class="tc-section">
    <div class="tc-section-title">D. General Commitments</div>
    <p style="font-size: 9.5pt;">
        As an appointed vendor of AMIOSH Resources Sdn. Bhd., you hereby acknowledge and agree to the
        following terms and conditions which govern the conduct, responsibilities, and expectations
        throughout the duration of this engagement:
    </p>
    <ol>
        <li>You shall comply with all Client site requirements, including the use of necessary personal protective equipment (PPE) such as safety shoes and safety helmets.</li>
        <li>You shall provide the services with due diligence, skill, and care, and in accordance with professional standards and industry best practices.</li>
        <li>You shall keep AMIOSH informed of the service progress and consult on matters requiring clarification. AMIOSH reserves the right to request variations, additions, or omissions to the scope of services as necessary to fulfill project or client requirements.</li>
        <li>Your engagement under this agreement is as an independent contractor. You are not authorized to act on behalf of, represent, or bind AMIOSH in any legal or financial capacity unless explicitly authorized in writing.</li>
        <li>You shall not publish, release, or communicate any statements, articles, reports, or commentary related to AMIOSH, its clients, or its operations to external parties or media without prior written approval.</li>
        <li>You must maintain strict confidentiality regarding all proprietary, financial, technical, or strategic information obtained during or after the duration of this engagement, including but not limited to project documents, pricing structures, methodologies, and internal systems.</li>
        <li>All information, data, or documentation created or shared in the course of this engagement remains the sole property of AMIOSH, unless otherwise stated in writing.</li>
        <li>Any form of misconduct including but not limited to dishonesty, insubordination, negligence, unauthorized absences, breach of confidentiality, or failure to perform may result in immediate termination of this engagement without prior notice, and AMIOSH reserves the right to seek legal redress or damages as appropriate.</li>
        <li>You shall not solicit, accept, or offer any gifts, commissions, or incentives that may influence or appear to influence business decisions or create a conflict of interest. Any such incident must be promptly reported to AMIOSH Management.</li>
        <li>You are responsible for the safekeeping and timely return of any AMIOSH property, equipment, documents, or resources provided to you for the performance of this engagement, in good working condition.</li>
        <li>All services and deliverables must comply with applicable local laws, client requirements, and any other standards or frameworks specified by AMIOSH.</li>
        <li>AMIOSH reserves the right to terminate this agreement at any time for breach of these terms or if, in its sole discretion, the vendor's continued engagement is not in the best interest of the company or its stakeholders.</li>
        <li>This Letter of Award shall be governed and construed in accordance with the laws of Malaysia. Any disputes arising shall be subject to the exclusive jurisdiction of the courts of Malaysia.</li>
    </ol>
</div>

</body>
</html>
