<?php

namespace App\Services\DeliveryOrders;

use App\Services\AuditLogService;
use App\Services\Word\CommercialWordDocumentBuilder;
use App\Services\Word\WordRenderer;
use App\Support\PdfLabels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeliveryOrderWordService extends WordRenderer
{
    public function __construct(private AuditLogService $auditLog, private CommercialWordDocumentBuilder $builder) {}

    public function generate(Request $request, int $id)
    {
        $order = DB::table('do_details')->where('id', $id)->first();
        if (! $order) {
            return response()->json(['status' => 'error', 'message' => 'Delivery Order not found.'], 404);
        }
        $language = PdfLabels::normalize($order->document_language ?? 'en');
        $rows = DB::table('do_breakdown')->where('do_id', $id)->orderBy('id')->get();
        $items = [];
        foreach ($rows as $index => $item) {
            $description = array_values(array_filter([
                (string) ($item->item_name ?? ''),
                trim((string) ($item->description ?? '')) !== '' ? PdfLabels::get($language, 'description', 'Description').': '.trim((string) $item->description) : '',
                trim((string) ($item->item_remarks ?? '')) !== '' ? PdfLabels::get($language, 'remarks', 'Remarks').': '.trim((string) $item->item_remarks) : '',
            ]));
            $items[] = [(string) ($index + 1), $description, number_format((float) ($item->quantity ?? 0), 2).' '.(string) ($item->unit ?? '')];
        }
        $project = trim(implode(' - ', array_filter([(string) ($order->project_name ?? ''), (string) ($order->project_description ?? '')])));
        if (trim((string) ($order->project_service_period ?? '')) !== '') {
            $project .= ($project !== '' ? ' | ' : '').(string) $order->project_service_period;
        }
        $isBm = $language === 'ms-MY';
        $data = [
            'kind' => 'delivery-order', 'documentType' => PdfLabels::documentType($language, 'DELIVERY ORDER'), 'language' => $language,
            'reference' => (string) ($order->do_number ?? '-'), 'date' => date('d M Y', strtotime((string) ($order->created_at ?? now()))),
            'recipient' => array_values(array_filter([(string) ($order->client_name ?? '-'), ...preg_split('/\R/', (string) ($order->client_address ?? '-')), ($isBm ? 'Pegawai Bertanggungjawab: ' : 'In Charge: ').(string) ($order->client_contact_name ?? '-').' ('.(string) ($order->client_contact_position ?? '-').')', ($isBm ? 'Hubungi: ' : 'Contact: ').(string) ($order->client_contact_email ?? '-').' ('.(string) ($order->client_contact_phone ?? '-').')'])),
            'sender' => ['AMIOSH Resources Sdn Bhd', LayoutText::address(), ($isBm ? 'Pegawai Bertanggungjawab: ' : 'In Charge: ').(string) ($order->company_contact_name ?? '-'), ($isBm ? 'Hubungi: ' : 'Contact: ').'03-8210 8726'],
            'intro' => $isBm ? 'Sila semak butiran penghantaran dan tandatangan di bawah sebagai pengakuan penerimaan. Sebarang isu hendaklah dilaporkan dalam tempoh lima (5) hari selepas penghantaran.' : 'Kindly review the delivery details and sign below as acknowledgement. Any issues should be reported within five (5) days of delivery.',
            'projectLabel' => $isBm ? 'Untuk Projek' : 'For Project', 'project' => $project !== '' ? $project : '-',
            'itemLabel' => $isBm ? 'Penerangan Item' : 'Item Description', 'items' => $items,
            'remarks' => trim((string) ($order->quotation_remarks ?? '')),
            'returnText' => $isBm
                ? 'Sila kembalikan salinan pesanan penghantaran yang telah ditandatangani sebagai pengesahan penerimaan anda.'
                : 'Kindly return a duly signed copy of this delivery order as confirmation of your acceptance.',
            'acceptanceHeading' => $isBm ? 'Penerimaan Pelanggan' : 'Customer Acceptance',
            'acceptanceText' => $isBm ? 'Kami dengan ini menerima item yang dihantar dan telah diperiksa dalam keadaan baik.' : 'We hereby accept the delivered items which have been checked to be in good order.',
            'acceptanceLeft' => [PdfLabels::get($language, 'name', 'Name'), PdfLabels::get($language, 'position', 'Position'), PdfLabels::get($language, 'signature', 'Signature')],
            'acceptanceRight' => [PdfLabels::get($language, 'company_stamp', 'Company Stamp'), PdfLabels::get($language, 'date', 'Date')],
            'computerGeneratedText' => PdfLabels::get($language, 'computer_generated', '[This is a computer-generated document. No signature is required from us.]'),
        ];
        $this->auditLog->log($request, "Generated delivery order Word document #{$id}");

        return parent::download($this->builder->build($data, $request), 'delivery-order-'.$data['reference'].'.docx');
    }
}
