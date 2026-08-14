<?php

namespace App\Support;

final class EquipmentQuotationLayout
{
    public const COMPANY_NAME = 'AMIOSH RESOURCES SDN BHD (1062417W)';

    public const COMPANY_ADDRESS_LINE_1 = 'No.5-2, Jalan Seri Putra 1/5, Bandar Seri Putra 1/5,';

    public const COMPANY_ADDRESS_LINE_2 = 'Bandar Seri Putra Bangi, 43000 Kajang Selangor, Malaysia.';

    public const COMPANY_CONTACT = 'amiosh.com  03-8210 8726';

    public const DOCUMENT_TYPE = 'QUOTATION';

    public const COLOR_MUTED = '696969';

    public const COLOR_FOOTER = '737373';

    public const COLOR_TABLE_BORDER = '999999';

    public const COLOR_TABLE_HEADER = 'F4F4F4';

    public const PAGE_WIDTH_MM = 210.0;

    public const PAGE_HEIGHT_MM = 297.0;

    public const MARGIN_TOP_MM = 36.0;

    public const WORD_MARGIN_TOP_MM = 36.0;

    public const MARGIN_BOTTOM_MM = 16.0;

    public const MARGIN_SIDE_MM = 20.0;

    public const HEADER_DISTANCE_MM = 10.0;

    public const FOOTER_DISTANCE_MM = 6.5;

    public const FOOTER_SIDE_MM = 7.0;

    public const TABLE_CELL_PADDING_MM = 1.15;

    public const WORD_QUOTATION_REMARKS_TOP_SPACING_PT = 6.5;

    public const WORD_POST_TABLE_SPACING_PT = 7.0;

    public const WORD_ACCEPTANCE_INTRO_TOP_SPACING_PT = 3.8;

    public const WORD_ACCEPTANCE_FLOW_OFFSET_PT = 0.3;

    public const WORD_TERMS_TEXT_INDENT_MM = 3.9;

    public const LOGO_WIDTH_MM = 42.0;

    public const HEADER_LEFT_PERCENT = 68;

    public const HEADER_RIGHT_PERCENT = 32;

    public const ITEM_COLUMN_PERCENTAGES = [5, 40, 10, 20, 25];

    public const ACCEPTANCE_HEIGHT_MM = 30.6;

    public static function printableWidthMm(): float
    {
        return self::PAGE_WIDTH_MM - (self::MARGIN_SIDE_MM * 2);
    }
}
