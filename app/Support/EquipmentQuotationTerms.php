<?php

namespace App\Support;

final class EquipmentQuotationTerms
{
    private const TERMS = [
        'This quotation is valid for thirty (30) calendar days from the date of issuance and is subject to equipment availability at the time of confirmation.',
        'All equipment prices are exclusive of SST unless expressly stated otherwise in this quotation.',
        'Payment terms are strictly thirty (30) days from the invoice date unless otherwise agreed in writing by both parties.',
        'Delivery and installation charges, where applicable, are not included unless explicitly specified in this quotation.',
        "All equipment supplied shall be covered under the respective manufacturer's warranty and maintenance conditions.",
        'The Client shall inspect all delivered equipment upon receipt and notify AMIOSH Resources Sdn. Bhd. in writing within three (3) days of any defects, damages, or discrepancies identified.',
        'Ownership of all equipment shall remain with AMIOSH Resources Sdn. Bhd. until full payment has been received and cleared.',
        'Requests for customization or special packaging may incur additional charges, subject to prior approval and written confirmation.',
        'Returns, exchanges, or cancellations by the Client are subject to prior written approval and may incur restocking or administrative fees.',
    ];

    public static function all(): array
    {
        return self::TERMS;
    }
}
