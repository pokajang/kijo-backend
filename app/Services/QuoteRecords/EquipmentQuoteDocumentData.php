<?php

namespace App\Services\QuoteRecords;

use App\Support\EquipmentItemSnapshot;
use App\Support\EquipmentQuotationTerms;
use Illuminate\Support\Facades\DB;

class EquipmentQuoteDocumentData
{
    public function find(int $quoteId): ?array
    {
        $quote = DB::table('quotes_equipment as qe')
            ->leftJoin('staff_general as sg', 'sg.staff_id', '=', 'qe.created_by_id')
            ->where('qe.id', $quoteId)
            ->select([
                'qe.*',
                'sg.position as staff_position',
                'sg.crm_position as crm_position',
                'sg.department as staff_department',
            ])
            ->first();

        if (! $quote) {
            return null;
        }

        $items = DB::table('quotes_equipment_items as qei')
            ->leftJoin('catalog_items as ci', 'ci.id', '=', 'qei.item_id')
            ->where('qei.quote_id', $quoteId)
            ->orderBy('qei.id')
            ->select([
                'qei.item_id',
                DB::raw(EquipmentItemSnapshot::expression('item_name', 'qei').' as title'),
                DB::raw(EquipmentItemSnapshot::expression('description', 'qei').' as description'),
                'qei.item_remarks',
                'qei.marked_up_price',
                'qei.quantity',
                'qei.line_total',
            ])
            ->get()
            ->map(function ($row): array {
                $item = (array) $row;
                $item['title'] = trim((string) ($item['title'] ?? '')) ?: 'Catalog item #'.$item['item_id'];

                return $item;
            })
            ->toArray();

        $staffTitle = trim((string) ($quote->staff_position ?? ''));
        $staffDepartment = trim((string) ($quote->staff_department ?? ''));
        $signOffTitle = trim((string) ($quote->crm_position ?? ''));
        if ($signOffTitle === '') {
            $signOffTitle = $staffTitle;
            if ($staffDepartment !== '') {
                $signOffTitle .= ($signOffTitle !== '' ? ' ' : '')."({$staffDepartment})";
            }
        }

        $sstPercent = (float) ($quote->sst_percent ?? 0);

        return [
            'quoteId' => $quoteId,
            'quoteRefNo' => (string) ($quote->quote_ref_no ?? ''),
            'revisionNo' => (int) ($quote->revision_no ?? 0),
            'createdDateLegacy' => $this->formattedDate($quote->created_at ?? null, 'd M Y'),
            'createdDateIso' => $this->formattedDate($quote->created_at ?? null, 'Y-m-d'),
            'updatedDateIso' => $this->formattedDate($quote->updated_at ?? null, 'Y-m-d'),
            'clientName' => (string) ($quote->client_name ?? '-'),
            'clientAddress' => $this->addressBlock(
                $quote->client_address ?? null,
                $quote->client_city ?? null,
                $quote->client_state ?? null,
                $quote->client_zip ?? null,
            ),
            'picName' => (string) ($quote->pic_name ?? '-'),
            'picEmail' => (string) ($quote->pic_email ?? '-'),
            'picPhone' => (string) ($quote->pic_phone ?? '-'),
            'quotationRemarks' => trim((string) ($quote->quotation_remarks ?? '')),
            'items' => $items,
            'lineItemsTotal' => (float) array_sum(array_column($items, 'line_total')),
            'deliveryCharge' => (float) ($quote->delivery_charge ?? 0),
            'miscCharge' => (float) ($quote->misc_charge ?? 0),
            'discountAmount' => (float) ($quote->discount ?? 0),
            'subTotalNet' => (float) ($quote->sub_total ?? 0),
            'sstAmount' => (float) ($quote->sst_amount ?? 0),
            'sstPercentLabel' => ((float) (int) $sstPercent === $sstPercent)
                ? number_format($sstPercent, 0)
                : number_format($sstPercent, 2),
            'grandTotal' => (float) ($quote->grand_total ?? 0),
            'preparedByName' => (string) ($quote->created_by_name ?? ''),
            'signOffTitle' => $signOffTitle !== '' ? $signOffTitle : 'Staff',
            'terms' => EquipmentQuotationTerms::all(),
        ];
    }

    private function formattedDate(mixed $value, string $format): string
    {
        if (empty($value)) {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? '' : date($format, $timestamp);
    }

    private function addressBlock(mixed ...$parts): string
    {
        $clean = static function (mixed $value): string {
            $text = trim((string) $value);

            return $text === '-' || strcasecmp($text, 'N/A') === 0 ? '' : $text;
        };
        $address = $clean($parts[0] ?? null);
        $location = implode(', ', array_filter(array_map($clean, array_slice($parts, 1))));
        $lines = array_filter([$address, $location]);

        return $lines ? implode("\n", $lines) : '-';
    }
}
