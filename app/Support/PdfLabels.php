<?php

namespace App\Support;

class PdfLabels
{
    private const BM = [
        'DOCUMENT' => 'DOKUMEN',
        'QUOTATION' => 'SEBUT HARGA',
        'SERVICE PROPOSAL' => 'CADANGAN PERKHIDMATAN',
        'BROCHURE' => 'BROSUR',
        'TAX INVOICE' => 'INVOIS CUKAI',
        'DELIVERY ORDER' => 'PESANAN PENGHANTARAN',
        'OFFICIAL RECEIPT' => 'RESIT RASMI',

        'quote_number' => 'No. Sebut Harga',
        'invoice_number' => 'No. Invois',
        'receipt_number' => 'No. Resit',
        'delivery_order_no' => 'No. Pesanan Penghantaran',
        'date' => 'Tarikh',
        'rev_date' => 'Tarikh Semakan',
        'ori_date' => 'Tarikh Asal',
        'attention_to' => 'Untuk Perhatian',
        'billed_to' => 'Dibilkan Kepada',
        'delivered_to' => 'Dihantar Kepada',
        'delivered_by' => 'Dihantar Oleh',
        'in_charge' => 'Pegawai Bertanggungjawab',
        'contact' => 'Hubungi',
        'email' => 'E-mel',
        'phone' => 'Telefon',
        'dear_valued_customer' => 'Pelanggan Yang Dihargai',
        'dear_hrd_officer' => 'Pegawai HRD Yang Dihormati',

        'training_details' => 'Butiran Latihan',
        'service_details' => 'Butiran Perkhidmatan',
        'service_title' => 'Tajuk Perkhidmatan',
        'service' => 'Perkhidmatan',
        'course_title' => 'Tajuk Kursus',
        'target_groups' => 'Kumpulan Sasaran',
        'venue' => 'Tempat',
        'site_address' => 'Alamat Tapak',
        'site_location' => 'Lokasi Tapak',
        'samples' => 'Sampel',
        'work_units' => 'Unit Kerja',
        'remarks' => 'Catatan',
        'important' => 'Penting',
        'unit_price_rm' => 'Harga Seunit (RM)',
        'unit_cost' => 'Kos Seunit',
        'amount_rm' => 'Amaun (RM)',
        'amount' => 'Amaun',
        'discount_rm' => 'Diskaun (RM)',
        'discount' => 'Diskaun',
        'subtotal_rm' => 'Jumlah Kecil (RM)',
        'subtotal' => 'Jumlah Kecil',
        'grand_total_rm' => 'Jumlah Keseluruhan (RM)',
        'grand_total' => 'Jumlah Keseluruhan',
        'sst_charge_rm' => 'Caj SST (RM)',
        'sst_charge' => 'Caj SST',
        'travel_charge_rm' => 'Caj Perjalanan (RM)',
        'meals_charge_rm' => 'Caj Makanan (RM)',
        'unit' => 'Unit',
        'qty' => 'Kuantiti',
        'description' => 'Penerangan',
        'item_service_details' => 'Butiran Item / Perkhidmatan',
        'total_paid_rm' => 'Jumlah Dibayar (RM)',
        'for_invoice' => 'Untuk invois',

        'prepared_by' => 'Disediakan oleh',
        'computer_generated' => '[Dokumen ini dijana oleh komputer. Tandatangan daripada kami tidak diperlukan.]',
        'no_signature_or_stamp' => '[Tiada tandatangan atau cop dalam fail]',
        'customer_acceptance' => 'Penerimaan Pelanggan',
        'acceptance_text' => 'Saya/Kami dengan ini menerima terma dan syarat yang dinyatakan dalam sebut harga ini dan mengesahkan hasrat kami untuk meneruskan.',
        'name' => 'Nama',
        'position' => 'Jawatan',
        'signature' => 'Tandatangan',
        'company_stamp' => 'Cop Syarikat',
        'terms_and_conditions' => 'Terma dan Syarat',
        'general' => 'Umum',
        'technical' => 'Teknikal',

        'training_intro' => 'Terima kasih atas minat anda terhadap perkhidmatan latihan kami. Kami berbesar hati untuk menyediakan sebut harga berikut untuk',
        'ih_intro' => 'Terima kasih atas minat anda terhadap perkhidmatan Higien Industri kami. Kami berbesar hati untuk menyediakan sebut harga berikut untuk',
        'manpower_intro' => 'Terima kasih atas minat anda terhadap perkhidmatan pembekalan tenaga kerja kami. Kami berbesar hati untuk menyediakan sebut harga berikut.',
        'special_intro' => 'Terima kasih atas minat anda terhadap perkhidmatan kami. Sila rujuk butiran sebut harga di bawah.',
        'review_terms' => 'Sila semak terma dan syarat pada halaman seterusnya, dan kembalikan salinan sebut harga yang telah ditandatangani sebagai pengesahan penerimaan anda.',
        'invoice_intro' => 'Kami menghargai urusan anda. Sila semak Invois Cukai di bawah untuk tindakan pihak anda.',
        'invoice_training_intro' => 'Sila rujuk invois cukai untuk program latihan yang telah kami jalankan seperti butiran di bawah.',
        'payment_instruction' => 'Sila buat bayaran ke akaun berikut:',
        'bank_name' => 'Nama Bank',
        'branch' => 'Cawangan',
        'account_name' => 'Nama Akaun',
        'account_number' => 'Nombor Akaun',
        'receipt_thanks' => 'Terima kasih atas bayaran anda. Kami sedia berkhidmat kepada anda lagi.',
    ];

    public static function normalize(?string $language): string
    {
        return match (strtolower(trim((string) $language))) {
            'bm', 'ms', 'ms-my', 'ms_my', 'bahasa', 'bahasa melayu' => 'ms-MY',
            default => 'en',
        };
    }

    public static function get(?string $language, string $key, ?string $fallback = null): string
    {
        if (self::normalize($language) === 'ms-MY') {
            return self::BM[$key] ?? $fallback ?? $key;
        }

        return $fallback ?? $key;
    }

    public static function documentType(?string $language, ?string $type): string
    {
        $type = strtoupper(trim((string) ($type ?: 'DOCUMENT')));

        return self::get($language, $type, $type);
    }
}
